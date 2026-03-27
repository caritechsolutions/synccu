"""Account CRUD routes with tenant scoping."""

from __future__ import annotations

import uuid
from decimal import Decimal
from typing import Any, Dict, List

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy import Column, Integer, Numeric, String, select, text
from sqlalchemy.ext.asyncio import AsyncSession

from database import Base, TenantMixin, TimestampMixin, get_db
from middleware.auth import get_current_user
from middleware.tenant import get_tenant_id
from models.schemas import AccountCreate, AccountResponse, AccountStatus, AccountType
from services.ledger import AccountBalance

router = APIRouter(prefix="/accounts", tags=["Accounts"])


# ---------------------------------------------------------------------------
# ORM model
# ---------------------------------------------------------------------------


class Account(Base, TenantMixin, TimestampMixin):
    __tablename__ = "accounts"

    id = Column(Integer, primary_key=True, autoincrement=True)
    account_number = Column(String(20), unique=True, nullable=False, index=True)
    account_type = Column(String(20), nullable=False)
    name = Column(String(200), nullable=False)
    balance = Column(Numeric(15, 2), nullable=False, server_default=text("0.00"))
    available_balance = Column(Numeric(15, 2), nullable=False, server_default=text("0.00"))
    currency = Column(String(3), nullable=False, server_default=text("'USD'"))
    status = Column(String(10), nullable=False, server_default=text("'active'"))
    user_id = Column(Integer, nullable=False, index=True)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _generate_account_number() -> str:
    return uuid.uuid4().hex[:16].upper()


# ---------------------------------------------------------------------------
# Routes
# ---------------------------------------------------------------------------


@router.post(
    "/",
    response_model=AccountResponse,
    status_code=status.HTTP_201_CREATED,
    summary="Open a new account",
)
async def create_account(
    body: AccountCreate,
    current_user: Dict[str, Any] = Depends(get_current_user),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> Account:
    user_id = int(current_user["sub"])

    account = Account(
        account_number=_generate_account_number(),
        account_type=body.account_type.value,
        name=body.name,
        balance=body.initial_deposit,
        available_balance=body.initial_deposit,
        currency=body.currency,
        status=AccountStatus.ACTIVE.value,
        user_id=user_id,
        tenant_id=tenant_id,
    )
    db.add(account)
    await db.flush()
    await db.refresh(account)

    # Create matching AccountBalance row for the ledger service
    bal = AccountBalance(
        account_id=account.id,
        balance=body.initial_deposit,
        available_balance=body.initial_deposit,
        currency=body.currency,
        tenant_id=tenant_id,
    )
    db.add(bal)
    await db.flush()

    return account


@router.get("/", response_model=List[AccountResponse], summary="List my accounts")
async def list_accounts(
    current_user: Dict[str, Any] = Depends(get_current_user),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
    status_filter: AccountStatus | None = Query(None, alias="status"),
    account_type: AccountType | None = Query(None),
) -> List[Account]:
    user_id = int(current_user["sub"])
    stmt = select(Account).where(
        Account.user_id == user_id,
        Account.tenant_id == tenant_id,
    )
    if status_filter:
        stmt = stmt.where(Account.status == status_filter.value)
    if account_type:
        stmt = stmt.where(Account.account_type == account_type.value)

    result = await db.execute(stmt.order_by(Account.created_at.desc()))
    return list(result.scalars().all())


@router.get("/{account_id}", response_model=AccountResponse, summary="Get account details")
async def get_account(
    account_id: int,
    current_user: Dict[str, Any] = Depends(get_current_user),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> Account:
    user_id = int(current_user["sub"])
    result = await db.execute(
        select(Account).where(
            Account.id == account_id,
            Account.user_id == user_id,
            Account.tenant_id == tenant_id,
        )
    )
    account = result.scalar_one_or_none()
    if account is None:
        raise HTTPException(status_code=404, detail="Account not found")
    return account


@router.patch("/{account_id}", response_model=AccountResponse, summary="Update account")
async def update_account(
    account_id: int,
    name: str | None = None,
    status_val: AccountStatus | None = Query(None, alias="status"),
    current_user: Dict[str, Any] = Depends(get_current_user),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> Account:
    user_id = int(current_user["sub"])
    result = await db.execute(
        select(Account).where(
            Account.id == account_id,
            Account.user_id == user_id,
            Account.tenant_id == tenant_id,
        )
    )
    account = result.scalar_one_or_none()
    if account is None:
        raise HTTPException(status_code=404, detail="Account not found")

    if name is not None:
        account.name = name
    if status_val is not None:
        account.status = status_val.value

    await db.flush()
    await db.refresh(account)
    return account


@router.delete(
    "/{account_id}",
    status_code=status.HTTP_204_NO_CONTENT,
    summary="Close account",
)
async def close_account(
    account_id: int,
    current_user: Dict[str, Any] = Depends(get_current_user),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> None:
    user_id = int(current_user["sub"])
    result = await db.execute(
        select(Account).where(
            Account.id == account_id,
            Account.user_id == user_id,
            Account.tenant_id == tenant_id,
        )
    )
    account = result.scalar_one_or_none()
    if account is None:
        raise HTTPException(status_code=404, detail="Account not found")

    if account.balance != Decimal("0.00"):
        raise HTTPException(
            status_code=400,
            detail="Account must have zero balance before closing",
        )

    account.status = AccountStatus.CLOSED.value
    await db.flush()
