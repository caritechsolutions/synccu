"""Loan routes: apply, approve, payments, amortisation schedule."""

from __future__ import annotations

from datetime import date, timedelta
from decimal import Decimal, ROUND_HALF_UP
from typing import Any, Dict, List, Optional

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy import Column, Date, Integer, Numeric, String, Text, select, text
from sqlalchemy.ext.asyncio import AsyncSession

from database import Base, TenantMixin, TimestampMixin, get_db
from middleware.auth import get_current_user, role_required
from middleware.tenant import get_tenant_id
from models.schemas import (
    LoanApplication,
    LoanPaymentRequest,
    LoanResponse,
    LoanScheduleEntry,
    LoanStatus,
    LoanType,
)
from services.ledger import LedgerService, InsufficientFundsError, AccountNotFoundError

router = APIRouter(prefix="/loans", tags=["Loans"])


# ---------------------------------------------------------------------------
# ORM model
# ---------------------------------------------------------------------------


class Loan(Base, TenantMixin, TimestampMixin):
    __tablename__ = "loans"

    id = Column(Integer, primary_key=True, autoincrement=True)
    loan_type = Column(String(20), nullable=False)
    principal = Column(Numeric(15, 2), nullable=False)
    interest_rate = Column(Numeric(6, 4), nullable=False)
    term_months = Column(Integer, nullable=False)
    monthly_payment = Column(Numeric(15, 2), nullable=False)
    remaining_balance = Column(Numeric(15, 2), nullable=False)
    status = Column(String(20), nullable=False, server_default=text("'pending'"))
    purpose = Column(Text, nullable=False)
    disbursement_account_id = Column(Integer, nullable=False)
    user_id = Column(Integer, nullable=False, index=True)
    next_payment_date = Column(Date, nullable=True)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

# Indicative rates by loan type
_DEFAULT_RATES: Dict[str, Decimal] = {
    LoanType.PERSONAL.value: Decimal("9.99"),
    LoanType.AUTO.value: Decimal("5.49"),
    LoanType.MORTGAGE.value: Decimal("6.75"),
    LoanType.CREDIT_BUILDER.value: Decimal("12.00"),
}


def _monthly_payment(principal: Decimal, annual_rate: Decimal, months: int) -> Decimal:
    """Standard amortisation formula."""
    if annual_rate == 0:
        return (principal / months).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
    r = annual_rate / Decimal("100") / Decimal("12")
    factor = (r * (1 + r) ** months) / ((1 + r) ** months - 1)
    return (principal * factor).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


def _amortisation_schedule(
    principal: Decimal, annual_rate: Decimal, months: int, start_date: date
) -> List[LoanScheduleEntry]:
    r = annual_rate / Decimal("100") / Decimal("12")
    payment = _monthly_payment(principal, annual_rate, months)
    balance = principal
    schedule: List[LoanScheduleEntry] = []

    for i in range(1, months + 1):
        interest = (balance * r).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
        principal_part = payment - interest
        if i == months:
            principal_part = balance
            payment = principal_part + interest
        balance = balance - principal_part
        if balance < 0:
            balance = Decimal("0.00")

        due = start_date + timedelta(days=30 * i)
        schedule.append(
            LoanScheduleEntry(
                payment_number=i,
                due_date=due,
                principal_amount=principal_part,
                interest_amount=interest,
                total_payment=payment,
                remaining_balance=balance,
            )
        )
    return schedule


# ---------------------------------------------------------------------------
# Routes
# ---------------------------------------------------------------------------


@router.post(
    "/apply",
    response_model=LoanResponse,
    status_code=status.HTTP_201_CREATED,
    summary="Submit a loan application",
)
async def apply_for_loan(
    body: LoanApplication,
    current_user: Dict[str, Any] = Depends(get_current_user),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> Loan:
    user_id = int(current_user["sub"])
    rate = _DEFAULT_RATES.get(body.loan_type.value, Decimal("9.99"))
    payment = _monthly_payment(body.amount, rate, body.term_months)

    loan = Loan(
        loan_type=body.loan_type.value,
        principal=body.amount,
        interest_rate=rate,
        term_months=body.term_months,
        monthly_payment=payment,
        remaining_balance=body.amount,
        status=LoanStatus.PENDING.value,
        purpose=body.purpose,
        disbursement_account_id=body.disbursement_account_id,
        user_id=user_id,
        tenant_id=tenant_id,
    )
    db.add(loan)
    await db.flush()
    await db.refresh(loan)
    return loan


@router.post(
    "/{loan_id}/approve",
    response_model=LoanResponse,
    summary="Approve a pending loan application",
)
async def approve_loan(
    loan_id: int,
    current_user: Dict[str, Any] = Depends(role_required(["manager", "admin"])),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> Loan:
    result = await db.execute(
        select(Loan).where(Loan.id == loan_id, Loan.tenant_id == tenant_id)
    )
    loan = result.scalar_one_or_none()
    if loan is None:
        raise HTTPException(status_code=404, detail="Loan not found")
    if loan.status != LoanStatus.PENDING.value:
        raise HTTPException(status_code=400, detail=f"Loan is already {loan.status}")

    # Disburse funds into the borrower's account
    ledger = LedgerService(db, tenant_id)
    try:
        await ledger.deposit(
            account_id=loan.disbursement_account_id,
            amount=loan.principal,
            description=f"Loan disbursement - Loan #{loan.id}",
        )
    except AccountNotFoundError as exc:
        raise HTTPException(status_code=404, detail=str(exc))

    # Update accounts table balance
    from routes.accounts import Account
    res = await db.execute(
        select(Account).where(
            Account.id == loan.disbursement_account_id,
            Account.tenant_id == tenant_id,
        )
    )
    acct = res.scalar_one_or_none()
    if acct:
        acct.balance = acct.balance + loan.principal
        acct.available_balance = acct.available_balance + loan.principal

    loan.status = LoanStatus.ACTIVE.value
    loan.next_payment_date = date.today() + timedelta(days=30)

    await db.flush()
    await db.refresh(loan)
    return loan


@router.post(
    "/{loan_id}/reject",
    response_model=LoanResponse,
    summary="Reject a pending loan application",
)
async def reject_loan(
    loan_id: int,
    current_user: Dict[str, Any] = Depends(role_required(["manager", "admin"])),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> Loan:
    result = await db.execute(
        select(Loan).where(Loan.id == loan_id, Loan.tenant_id == tenant_id)
    )
    loan = result.scalar_one_or_none()
    if loan is None:
        raise HTTPException(status_code=404, detail="Loan not found")
    if loan.status != LoanStatus.PENDING.value:
        raise HTTPException(status_code=400, detail=f"Loan is already {loan.status}")

    loan.status = LoanStatus.REJECTED.value
    await db.flush()
    await db.refresh(loan)
    return loan


@router.post(
    "/{loan_id}/payment",
    response_model=LoanResponse,
    summary="Make a loan payment",
)
async def make_payment(
    loan_id: int,
    body: LoanPaymentRequest,
    current_user: Dict[str, Any] = Depends(get_current_user),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> Loan:
    user_id = int(current_user["sub"])
    result = await db.execute(
        select(Loan).where(
            Loan.id == loan_id,
            Loan.user_id == user_id,
            Loan.tenant_id == tenant_id,
        )
    )
    loan = result.scalar_one_or_none()
    if loan is None:
        raise HTTPException(status_code=404, detail="Loan not found")
    if loan.status != LoanStatus.ACTIVE.value:
        raise HTTPException(status_code=400, detail="Loan is not active")

    ledger = LedgerService(db, tenant_id)
    try:
        await ledger.withdraw(
            account_id=body.from_account_id,
            amount=body.amount,
            description=f"Loan payment - Loan #{loan.id}",
        )
    except InsufficientFundsError as exc:
        raise HTTPException(status_code=400, detail=str(exc))
    except AccountNotFoundError as exc:
        raise HTTPException(status_code=404, detail=str(exc))

    # Update accounts table
    from routes.accounts import Account
    res = await db.execute(
        select(Account).where(
            Account.id == body.from_account_id,
            Account.tenant_id == tenant_id,
        )
    )
    acct = res.scalar_one_or_none()
    if acct:
        acct.balance = acct.balance - body.amount
        acct.available_balance = acct.available_balance - body.amount

    loan.remaining_balance = loan.remaining_balance - body.amount
    if loan.remaining_balance <= 0:
        loan.remaining_balance = Decimal("0.00")
        loan.status = LoanStatus.PAID_OFF.value
        loan.next_payment_date = None
    else:
        if loan.next_payment_date:
            loan.next_payment_date = loan.next_payment_date + timedelta(days=30)

    await db.flush()
    await db.refresh(loan)
    return loan


@router.get(
    "/{loan_id}/schedule",
    response_model=List[LoanScheduleEntry],
    summary="Get amortisation schedule",
)
async def get_schedule(
    loan_id: int,
    current_user: Dict[str, Any] = Depends(get_current_user),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> List[LoanScheduleEntry]:
    user_id = int(current_user["sub"])
    result = await db.execute(
        select(Loan).where(
            Loan.id == loan_id,
            Loan.user_id == user_id,
            Loan.tenant_id == tenant_id,
        )
    )
    loan = result.scalar_one_or_none()
    if loan is None:
        raise HTTPException(status_code=404, detail="Loan not found")

    start = loan.next_payment_date or date.today()
    return _amortisation_schedule(loan.principal, loan.interest_rate, loan.term_months, start)


@router.get("/", response_model=List[LoanResponse], summary="List my loans")
async def list_loans(
    current_user: Dict[str, Any] = Depends(get_current_user),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
    loan_status: Optional[LoanStatus] = Query(None, alias="status"),
    limit: int = Query(50, ge=1, le=200),
    offset: int = Query(0, ge=0),
) -> List[Loan]:
    user_id = int(current_user["sub"])
    stmt = select(Loan).where(Loan.user_id == user_id, Loan.tenant_id == tenant_id)
    if loan_status:
        stmt = stmt.where(Loan.status == loan_status.value)
    stmt = stmt.order_by(Loan.created_at.desc()).limit(limit).offset(offset)
    result = await db.execute(stmt)
    return list(result.scalars().all())


@router.get("/{loan_id}", response_model=LoanResponse, summary="Get loan details")
async def get_loan(
    loan_id: int,
    current_user: Dict[str, Any] = Depends(get_current_user),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> Loan:
    user_id = int(current_user["sub"])
    result = await db.execute(
        select(Loan).where(
            Loan.id == loan_id,
            Loan.user_id == user_id,
            Loan.tenant_id == tenant_id,
        )
    )
    loan = result.scalar_one_or_none()
    if loan is None:
        raise HTTPException(status_code=404, detail="Loan not found")
    return loan
