"""Admin routes: dashboard stats, user management, audit logs, reports."""

from __future__ import annotations

from datetime import datetime, timedelta, timezone
from decimal import Decimal
from typing import Any, Dict, List, Optional

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy import Column, DateTime, Integer, String, Text, func, select, text
from sqlalchemy.ext.asyncio import AsyncSession

from database import Base, TenantMixin, TimestampMixin, get_db
from middleware.auth import get_current_user, role_required
from middleware.tenant import get_tenant_id
from models.schemas import AuditLogEntry, DashboardStats, UserResponse, UserRole

router = APIRouter(prefix="/admin", tags=["Administration"])


# ---------------------------------------------------------------------------
# Audit log ORM model
# ---------------------------------------------------------------------------


class AuditLog(Base, TenantMixin, TimestampMixin):
    __tablename__ = "audit_logs"

    id = Column(Integer, primary_key=True, autoincrement=True)
    user_id = Column(Integer, nullable=True, index=True)
    action = Column(String(100), nullable=False)
    resource = Column(String(100), nullable=False)
    resource_id = Column(String(100), nullable=True)
    ip_address = Column(String(45), nullable=True)
    user_agent = Column(Text, nullable=True)
    details = Column(Text, nullable=True)


# ---------------------------------------------------------------------------
# Dashboard
# ---------------------------------------------------------------------------


@router.get(
    "/dashboard",
    response_model=DashboardStats,
    summary="Get dashboard statistics",
)
async def dashboard_stats(
    current_user: Dict[str, Any] = Depends(role_required(["manager", "admin"])),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> DashboardStats:
    from routes.auth import User
    from routes.accounts import Account
    from routes.transactions import Transaction
    from routes.loans import Loan

    now = datetime.now(timezone.utc)
    today_start = now.replace(hour=0, minute=0, second=0, microsecond=0)
    month_start = now.replace(day=1, hour=0, minute=0, second=0, microsecond=0)

    # Total members
    r = await db.execute(
        select(func.count(User.id)).where(User.tenant_id == tenant_id, User.is_active == True)
    )
    total_members = r.scalar() or 0

    # Total accounts
    r = await db.execute(
        select(func.count(Account.id)).where(Account.tenant_id == tenant_id)
    )
    total_accounts = r.scalar() or 0

    # Total deposits (sum of balances)
    r = await db.execute(
        select(func.coalesce(func.sum(Account.balance), 0)).where(
            Account.tenant_id == tenant_id, Account.status == "active"
        )
    )
    total_deposits = r.scalar() or Decimal("0")

    # Outstanding loans
    r = await db.execute(
        select(func.coalesce(func.sum(Loan.remaining_balance), 0)).where(
            Loan.tenant_id == tenant_id, Loan.status == "active"
        )
    )
    total_loans_outstanding = r.scalar() or Decimal("0")

    # Active loans count
    r = await db.execute(
        select(func.count(Loan.id)).where(
            Loan.tenant_id == tenant_id, Loan.status == "active"
        )
    )
    active_loans = r.scalar() or 0

    # Pending loan applications
    r = await db.execute(
        select(func.count(Loan.id)).where(
            Loan.tenant_id == tenant_id, Loan.status == "pending"
        )
    )
    pending_loan_applications = r.scalar() or 0

    # Transactions today
    r = await db.execute(
        select(func.count(Transaction.id)).where(
            Transaction.tenant_id == tenant_id,
            Transaction.created_at >= today_start,
        )
    )
    transactions_today = r.scalar() or 0

    # New members this month
    r = await db.execute(
        select(func.count(User.id)).where(
            User.tenant_id == tenant_id,
            User.created_at >= month_start,
        )
    )
    new_members_this_month = r.scalar() or 0

    return DashboardStats(
        total_members=total_members,
        total_accounts=total_accounts,
        total_deposits=total_deposits,
        total_loans_outstanding=total_loans_outstanding,
        active_loans=active_loans,
        pending_loan_applications=pending_loan_applications,
        transactions_today=transactions_today,
        new_members_this_month=new_members_this_month,
    )


# ---------------------------------------------------------------------------
# User management
# ---------------------------------------------------------------------------


@router.get("/users", response_model=List[UserResponse], summary="List all users")
async def list_users(
    current_user: Dict[str, Any] = Depends(role_required(["admin"])),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
    role: Optional[UserRole] = Query(None),
    is_active: Optional[bool] = Query(None),
    limit: int = Query(50, ge=1, le=200),
    offset: int = Query(0, ge=0),
) -> List[Any]:
    from routes.auth import User

    stmt = select(User).where(User.tenant_id == tenant_id)
    if role:
        stmt = stmt.where(User.role == role.value)
    if is_active is not None:
        stmt = stmt.where(User.is_active == is_active)
    stmt = stmt.order_by(User.created_at.desc()).limit(limit).offset(offset)
    result = await db.execute(stmt)
    return list(result.scalars().all())


@router.get("/users/{user_id}", response_model=UserResponse, summary="Get user details")
async def get_user(
    user_id: int,
    current_user: Dict[str, Any] = Depends(role_required(["admin"])),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> Any:
    from routes.auth import User

    result = await db.execute(
        select(User).where(User.id == user_id, User.tenant_id == tenant_id)
    )
    user = result.scalar_one_or_none()
    if user is None:
        raise HTTPException(status_code=404, detail="User not found")
    return user


@router.patch("/users/{user_id}/role", response_model=UserResponse, summary="Change user role")
async def update_user_role(
    user_id: int,
    role: UserRole,
    current_user: Dict[str, Any] = Depends(role_required(["admin"])),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> Any:
    from routes.auth import User

    result = await db.execute(
        select(User).where(User.id == user_id, User.tenant_id == tenant_id)
    )
    user = result.scalar_one_or_none()
    if user is None:
        raise HTTPException(status_code=404, detail="User not found")

    user.role = role.value
    await db.flush()
    await db.refresh(user)
    return user


@router.patch(
    "/users/{user_id}/deactivate",
    response_model=UserResponse,
    summary="Deactivate a user",
)
async def deactivate_user(
    user_id: int,
    current_user: Dict[str, Any] = Depends(role_required(["admin"])),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> Any:
    from routes.auth import User

    if str(user_id) == current_user.get("sub"):
        raise HTTPException(status_code=400, detail="Cannot deactivate yourself")

    result = await db.execute(
        select(User).where(User.id == user_id, User.tenant_id == tenant_id)
    )
    user = result.scalar_one_or_none()
    if user is None:
        raise HTTPException(status_code=404, detail="User not found")

    user.is_active = False
    await db.flush()
    await db.refresh(user)
    return user


@router.patch(
    "/users/{user_id}/activate",
    response_model=UserResponse,
    summary="Re-activate a user",
)
async def activate_user(
    user_id: int,
    current_user: Dict[str, Any] = Depends(role_required(["admin"])),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
) -> Any:
    from routes.auth import User

    result = await db.execute(
        select(User).where(User.id == user_id, User.tenant_id == tenant_id)
    )
    user = result.scalar_one_or_none()
    if user is None:
        raise HTTPException(status_code=404, detail="User not found")

    user.is_active = True
    await db.flush()
    await db.refresh(user)
    return user


# ---------------------------------------------------------------------------
# Audit logs
# ---------------------------------------------------------------------------


@router.get("/audit-logs", response_model=List[AuditLogEntry], summary="Query audit logs")
async def list_audit_logs(
    current_user: Dict[str, Any] = Depends(role_required(["admin"])),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
    user_id: Optional[int] = Query(None),
    action: Optional[str] = Query(None),
    resource: Optional[str] = Query(None),
    limit: int = Query(100, ge=1, le=500),
    offset: int = Query(0, ge=0),
) -> List[AuditLog]:
    stmt = select(AuditLog).where(AuditLog.tenant_id == tenant_id)
    if user_id is not None:
        stmt = stmt.where(AuditLog.user_id == user_id)
    if action:
        stmt = stmt.where(AuditLog.action == action)
    if resource:
        stmt = stmt.where(AuditLog.resource == resource)

    stmt = stmt.order_by(AuditLog.created_at.desc()).limit(limit).offset(offset)
    result = await db.execute(stmt)
    return list(result.scalars().all())


# ---------------------------------------------------------------------------
# Reports
# ---------------------------------------------------------------------------


@router.get("/reports/summary", summary="Financial summary report")
async def financial_summary(
    current_user: Dict[str, Any] = Depends(role_required(["manager", "admin"])),
    tenant_id: str = Depends(get_tenant_id),
    db: AsyncSession = Depends(get_db),
    days: int = Query(30, ge=1, le=365),
) -> Dict[str, Any]:
    from routes.transactions import Transaction
    from routes.loans import Loan
    from routes.accounts import Account

    cutoff = datetime.now(timezone.utc) - timedelta(days=days)

    # Transaction volume
    r = await db.execute(
        select(
            func.count(Transaction.id).label("count"),
            func.coalesce(func.sum(Transaction.amount), 0).label("volume"),
        ).where(
            Transaction.tenant_id == tenant_id,
            Transaction.created_at >= cutoff,
        )
    )
    row = r.one()
    txn_count = row.count
    txn_volume = row.volume

    # Deposits vs withdrawals
    r = await db.execute(
        select(
            Transaction.transaction_type,
            func.coalesce(func.sum(Transaction.amount), 0).label("total"),
        )
        .where(
            Transaction.tenant_id == tenant_id,
            Transaction.created_at >= cutoff,
        )
        .group_by(Transaction.transaction_type)
    )
    by_type = {row.transaction_type: float(row.total) for row in r.all()}

    # Loan origination
    r = await db.execute(
        select(func.count(Loan.id), func.coalesce(func.sum(Loan.principal), 0)).where(
            Loan.tenant_id == tenant_id,
            Loan.created_at >= cutoff,
        )
    )
    loan_row = r.one()

    return {
        "period_days": days,
        "transaction_count": txn_count,
        "transaction_volume": float(txn_volume),
        "transactions_by_type": by_type,
        "loans_originated": loan_row[0],
        "loans_originated_volume": float(loan_row[1]),
    }
