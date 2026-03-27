"""Transaction routes: deposit, withdraw, transfer, list."""

from __future__ import annotations

import uuid
from decimal import Decimal
from typing import Any, Dict, List, Optional

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy import Column, DateTime, Integer, Numeric, String, Text, func, select, text
from sqlalchemy.ext.asyncio import AsyncSession

from database import Base, TenantMixin, TimestampMixin, get_db
from middleware.auth import get_current_user
from middleware.tenant import get_tenant_id
from models.schemas import TransactionCreate, TransactionResponse, TransactionType
from services.ledger import AccountNotFoundError, InsufficientFundsError, LedgerService

router = APIRouter(prefix="/transactions", tags=["Transactions"])


# ---------------------------------------------------------------------------
# ORM model
# ---------------------------------------------------------------------------


class Transaction(Base, TenantMixin, TimestampMixin):
    __tablename__ = "transactions"

    id = Column(Integer, primary_key=True, autoincrement=True)
    transaction_type = Column(String(20), nullable=False)
    amount = Column(Numeric(15, 2), nullable=False)
    from_account_id = Column(Integer, nullable=True, index=True)
    to_account_id = Column(Integer, nullable=True, index=True)
    description = Column(Text, nullable=True)
    reference = Column(String(100), nullable=True, unique=True, index=True)
    status = Column(String(20), nullable=False, server_default=text("'completed'"))
    user_id = Column(Integer, nullable=False, index=True)


# ---------------------------------------------------------------------------
# Routes
# ---------------------------------------------------------------------------


@router.post(
    "/deposit",
    response_model=TransactionResponse,
    status_code=status.HTTP_201_CREATED,
    summary="Deposit funds into an account",
)
async def deposit(
    body: TransactionCreate,
    current_user: Dict[str, Any] = Depends(get_current_user),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> Transaction:
    if body.to_account_id is None:
        raise HTTPException(status_code=400, detail="to_account_id is required for deposits")

    user_id = int(current_user["sub"])
    ref = body.reference or f"DEP-{uuid.uuid4().hex[:12].upper()}"
    ledger = LedgerService(db, tenant_id)

    try:
        await ledger.deposit(
            account_id=body.to_account_id,
            amount=body.amount,
            description=body.description or "Deposit",
            reference=ref,
        )
    except AccountNotFoundError as exc:
        raise HTTPException(status_code=404, detail=str(exc))

    # Update the accounts table balance too
    from routes.accounts import Account
    result = await db.execute(
        select(Account).where(Account.id == body.to_account_id, Account.tenant_id == tenant_id)
    )
    acct = result.scalar_one_or_none()
    if acct:
        acct.balance = acct.balance + body.amount
        acct.available_balance = acct.available_balance + body.amount

    txn = Transaction(
        transaction_type=TransactionType.DEPOSIT.value,
        amount=body.amount,
        to_account_id=body.to_account_id,
        description=body.description or "Deposit",
        reference=ref,
        status="completed",
        user_id=user_id,
        tenant_id=tenant_id,
    )
    db.add(txn)
    await db.flush()
    await db.refresh(txn)
    return txn


@router.post(
    "/withdraw",
    response_model=TransactionResponse,
    status_code=status.HTTP_201_CREATED,
    summary="Withdraw funds from an account",
)
async def withdraw(
    body: TransactionCreate,
    current_user: Dict[str, Any] = Depends(get_current_user),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> Transaction:
    if body.from_account_id is None:
        raise HTTPException(status_code=400, detail="from_account_id is required for withdrawals")

    user_id = int(current_user["sub"])
    ref = body.reference or f"WDR-{uuid.uuid4().hex[:12].upper()}"
    ledger = LedgerService(db, tenant_id)

    try:
        await ledger.withdraw(
            account_id=body.from_account_id,
            amount=body.amount,
            description=body.description or "Withdrawal",
            reference=ref,
        )
    except AccountNotFoundError as exc:
        raise HTTPException(status_code=404, detail=str(exc))
    except InsufficientFundsError as exc:
        raise HTTPException(status_code=400, detail=str(exc))

    from routes.accounts import Account
    result = await db.execute(
        select(Account).where(Account.id == body.from_account_id, Account.tenant_id == tenant_id)
    )
    acct = result.scalar_one_or_none()
    if acct:
        acct.balance = acct.balance - body.amount
        acct.available_balance = acct.available_balance - body.amount

    txn = Transaction(
        transaction_type=TransactionType.WITHDRAWAL.value,
        amount=body.amount,
        from_account_id=body.from_account_id,
        description=body.description or "Withdrawal",
        reference=ref,
        status="completed",
        user_id=user_id,
        tenant_id=tenant_id,
    )
    db.add(txn)
    await db.flush()
    await db.refresh(txn)
    return txn


@router.post(
    "/transfer",
    response_model=TransactionResponse,
    status_code=status.HTTP_201_CREATED,
    summary="Transfer funds between accounts",
)
async def transfer(
    body: TransactionCreate,
    current_user: Dict[str, Any] = Depends(get_current_user),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> Transaction:
    if body.from_account_id is None or body.to_account_id is None:
        raise HTTPException(
            status_code=400,
            detail="Both from_account_id and to_account_id are required for transfers",
        )

    if body.from_account_id == body.to_account_id:
        raise HTTPException(status_code=400, detail="Cannot transfer to the same account")

    user_id = int(current_user["sub"])
    ref = body.reference or f"TRF-{uuid.uuid4().hex[:12].upper()}"
    ledger = LedgerService(db, tenant_id)

    try:
        await ledger.transfer(
            from_account_id=body.from_account_id,
            to_account_id=body.to_account_id,
            amount=body.amount,
            description=body.description or "Transfer",
            reference=ref,
        )
    except AccountNotFoundError as exc:
        raise HTTPException(status_code=404, detail=str(exc))
    except InsufficientFundsError as exc:
        raise HTTPException(status_code=400, detail=str(exc))

    from routes.accounts import Account
    for acct_id, delta in [(body.from_account_id, -body.amount), (body.to_account_id, body.amount)]:
        result = await db.execute(
            select(Account).where(Account.id == acct_id, Account.tenant_id == tenant_id)
        )
        acct = result.scalar_one_or_none()
        if acct:
            acct.balance = acct.balance + delta
            acct.available_balance = acct.available_balance + delta

    txn = Transaction(
        transaction_type=TransactionType.TRANSFER.value,
        amount=body.amount,
        from_account_id=body.from_account_id,
        to_account_id=body.to_account_id,
        description=body.description or "Transfer",
        reference=ref,
        status="completed",
        user_id=user_id,
        tenant_id=tenant_id,
    )
    db.add(txn)
    await db.flush()
    await db.refresh(txn)
    return txn


@router.get("/", response_model=List[TransactionResponse], summary="List transactions")
async def list_transactions(
    current_user: Dict[str, Any] = Depends(get_current_user),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
    account_id: Optional[int] = Query(None),
    transaction_type: Optional[TransactionType] = Query(None),
    limit: int = Query(50, ge=1, le=200),
    offset: int = Query(0, ge=0),
) -> List[Transaction]:
    user_id = int(current_user["sub"])
    stmt = select(Transaction).where(
        Transaction.user_id == user_id,
        Transaction.tenant_id == tenant_id,
    )

    if account_id is not None:
        stmt = stmt.where(
            (Transaction.from_account_id == account_id)
            | (Transaction.to_account_id == account_id)
        )
    if transaction_type is not None:
        stmt = stmt.where(Transaction.transaction_type == transaction_type.value)

    stmt = stmt.order_by(Transaction.created_at.desc()).limit(limit).offset(offset)
    result = await db.execute(stmt)
    return list(result.scalars().all())
