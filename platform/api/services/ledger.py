"""Double-entry ledger service with transaction atomicity.

Every financial operation creates a balanced pair of ledger entries (debit and
credit) inside a single database transaction so that the books always balance.
"""

from __future__ import annotations

from datetime import datetime, timezone
from decimal import Decimal
from enum import Enum
from typing import Any, Dict, List, Optional

from sqlalchemy import Column, DateTime, Integer, Numeric, String, Text, func, text
from sqlalchemy.ext.asyncio import AsyncSession

from database import Base, TenantMixin, TimestampMixin


# ---------------------------------------------------------------------------
# ORM models for the ledger
# ---------------------------------------------------------------------------


class LedgerEntryType(str, Enum):
    DEBIT = "debit"
    CREDIT = "credit"


class LedgerEntry(Base, TenantMixin, TimestampMixin):
    """A single debit or credit line in the general ledger."""

    __tablename__ = "ledger_entries"

    id = Column(Integer, primary_key=True, autoincrement=True)
    transaction_ref = Column(String(64), nullable=False, index=True)
    account_id = Column(Integer, nullable=False, index=True)
    entry_type = Column(String(10), nullable=False)  # debit | credit
    amount = Column(Numeric(15, 2), nullable=False)
    balance_after = Column(Numeric(15, 2), nullable=False)
    description = Column(Text, nullable=True)
    created_at = Column(DateTime, server_default=func.now(), nullable=False)


class AccountBalance(Base, TenantMixin, TimestampMixin):
    """Materialised account balance (updated atomically with ledger writes)."""

    __tablename__ = "account_balances"

    id = Column(Integer, primary_key=True, autoincrement=True)
    account_id = Column(Integer, unique=True, nullable=False, index=True)
    balance = Column(Numeric(15, 2), nullable=False, server_default=text("0.00"))
    available_balance = Column(Numeric(15, 2), nullable=False, server_default=text("0.00"))
    currency = Column(String(3), nullable=False, server_default=text("'USD'"))


# ---------------------------------------------------------------------------
# Service
# ---------------------------------------------------------------------------


class LedgerService:
    """Provides atomic double-entry bookkeeping operations."""

    def __init__(self, session: AsyncSession, tenant_id: str) -> None:
        self._session = session
        self._tenant_id = tenant_id

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    async def deposit(
        self,
        account_id: int,
        amount: Decimal,
        description: str = "Deposit",
        reference: Optional[str] = None,
    ) -> Dict[str, Any]:
        """Credit an account (increase balance)."""
        self._validate_amount(amount)
        ref = reference or self._generate_ref()

        balance_row = await self._get_balance(account_id)
        new_balance = balance_row.balance + amount

        # Credit entry on the member's account
        credit_entry = LedgerEntry(
            transaction_ref=ref,
            account_id=account_id,
            entry_type=LedgerEntryType.CREDIT.value,
            amount=amount,
            balance_after=new_balance,
            description=description,
            tenant_id=self._tenant_id,
        )
        # Debit entry on the institution's cash account (account_id=0)
        debit_entry = LedgerEntry(
            transaction_ref=ref,
            account_id=0,
            entry_type=LedgerEntryType.DEBIT.value,
            amount=amount,
            balance_after=Decimal("0"),  # institution cash not tracked here
            description=f"Counter-entry: {description}",
            tenant_id=self._tenant_id,
        )

        balance_row.balance = new_balance
        balance_row.available_balance = new_balance

        self._session.add_all([credit_entry, debit_entry])
        await self._session.flush()

        return {
            "reference": ref,
            "account_id": account_id,
            "amount": amount,
            "new_balance": new_balance,
            "entry_type": "credit",
        }

    async def withdraw(
        self,
        account_id: int,
        amount: Decimal,
        description: str = "Withdrawal",
        reference: Optional[str] = None,
    ) -> Dict[str, Any]:
        """Debit an account (decrease balance)."""
        self._validate_amount(amount)
        ref = reference or self._generate_ref()

        balance_row = await self._get_balance(account_id)
        if balance_row.available_balance < amount:
            raise InsufficientFundsError(
                f"Available balance {balance_row.available_balance} is less than {amount}"
            )

        new_balance = balance_row.balance - amount

        debit_entry = LedgerEntry(
            transaction_ref=ref,
            account_id=account_id,
            entry_type=LedgerEntryType.DEBIT.value,
            amount=amount,
            balance_after=new_balance,
            description=description,
            tenant_id=self._tenant_id,
        )
        credit_entry = LedgerEntry(
            transaction_ref=ref,
            account_id=0,
            entry_type=LedgerEntryType.CREDIT.value,
            amount=amount,
            balance_after=Decimal("0"),
            description=f"Counter-entry: {description}",
            tenant_id=self._tenant_id,
        )

        balance_row.balance = new_balance
        balance_row.available_balance = new_balance

        self._session.add_all([debit_entry, credit_entry])
        await self._session.flush()

        return {
            "reference": ref,
            "account_id": account_id,
            "amount": amount,
            "new_balance": new_balance,
            "entry_type": "debit",
        }

    async def transfer(
        self,
        from_account_id: int,
        to_account_id: int,
        amount: Decimal,
        description: str = "Transfer",
        reference: Optional[str] = None,
    ) -> Dict[str, Any]:
        """Atomically move funds between two accounts."""
        self._validate_amount(amount)
        ref = reference or self._generate_ref()

        from_bal = await self._get_balance(from_account_id)
        to_bal = await self._get_balance(to_account_id)

        if from_bal.available_balance < amount:
            raise InsufficientFundsError(
                f"Available balance {from_bal.available_balance} is less than {amount}"
            )

        new_from = from_bal.balance - amount
        new_to = to_bal.balance + amount

        entries = [
            LedgerEntry(
                transaction_ref=ref,
                account_id=from_account_id,
                entry_type=LedgerEntryType.DEBIT.value,
                amount=amount,
                balance_after=new_from,
                description=f"{description} to account {to_account_id}",
                tenant_id=self._tenant_id,
            ),
            LedgerEntry(
                transaction_ref=ref,
                account_id=to_account_id,
                entry_type=LedgerEntryType.CREDIT.value,
                amount=amount,
                balance_after=new_to,
                description=f"{description} from account {from_account_id}",
                tenant_id=self._tenant_id,
            ),
        ]

        from_bal.balance = new_from
        from_bal.available_balance = new_from
        to_bal.balance = new_to
        to_bal.available_balance = new_to

        self._session.add_all(entries)
        await self._session.flush()

        return {
            "reference": ref,
            "from_account_id": from_account_id,
            "to_account_id": to_account_id,
            "amount": amount,
            "from_new_balance": new_from,
            "to_new_balance": new_to,
        }

    async def get_entries(
        self, account_id: int, limit: int = 50, offset: int = 0
    ) -> List[LedgerEntry]:
        """Return ledger entries for an account, newest first."""
        from sqlalchemy import select

        stmt = (
            select(LedgerEntry)
            .where(
                LedgerEntry.account_id == account_id,
                LedgerEntry.tenant_id == self._tenant_id,
            )
            .order_by(LedgerEntry.created_at.desc())
            .limit(limit)
            .offset(offset)
        )
        result = await self._session.execute(stmt)
        return list(result.scalars().all())

    # ------------------------------------------------------------------
    # Internals
    # ------------------------------------------------------------------

    async def _get_balance(self, account_id: int) -> AccountBalance:
        from sqlalchemy import select

        stmt = select(AccountBalance).where(
            AccountBalance.account_id == account_id,
            AccountBalance.tenant_id == self._tenant_id,
        )
        result = await self._session.execute(stmt)
        row = result.scalar_one_or_none()
        if row is None:
            raise AccountNotFoundError(f"Account {account_id} not found in tenant {self._tenant_id}")
        return row

    @staticmethod
    def _validate_amount(amount: Decimal) -> None:
        if amount <= 0:
            raise ValueError("Amount must be positive")
        if amount != amount.quantize(Decimal("0.01")):
            raise ValueError("Amount must have at most 2 decimal places")

    @staticmethod
    def _generate_ref() -> str:
        import uuid
        return f"TXN-{uuid.uuid4().hex[:16].upper()}"


# ---------------------------------------------------------------------------
# Domain exceptions
# ---------------------------------------------------------------------------


class InsufficientFundsError(Exception):
    """Raised when an account has insufficient available balance."""


class AccountNotFoundError(Exception):
    """Raised when the requested account does not exist in the tenant."""
