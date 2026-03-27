"""Pydantic v2 models for every request / response in the API."""

from __future__ import annotations

from datetime import date, datetime
from decimal import Decimal
from enum import Enum
from typing import List, Optional

from pydantic import BaseModel, ConfigDict, EmailStr, Field, field_validator


# ---------------------------------------------------------------------------
# Enums
# ---------------------------------------------------------------------------

class AccountType(str, Enum):
    CHECKING = "checking"
    SAVINGS = "savings"
    MONEY_MARKET = "money_market"
    CERTIFICATE = "certificate"


class AccountStatus(str, Enum):
    ACTIVE = "active"
    FROZEN = "frozen"
    CLOSED = "closed"


class TransactionType(str, Enum):
    DEPOSIT = "deposit"
    WITHDRAWAL = "withdrawal"
    TRANSFER = "transfer"
    LOAN_PAYMENT = "loan_payment"
    INTEREST = "interest"
    FEE = "fee"


class LoanStatus(str, Enum):
    PENDING = "pending"
    APPROVED = "approved"
    REJECTED = "rejected"
    ACTIVE = "active"
    PAID_OFF = "paid_off"
    DEFAULTED = "defaulted"


class LoanType(str, Enum):
    PERSONAL = "personal"
    AUTO = "auto"
    MORTGAGE = "mortgage"
    CREDIT_BUILDER = "credit_builder"


class UserRole(str, Enum):
    MEMBER = "member"
    TELLER = "teller"
    MANAGER = "manager"
    ADMIN = "admin"


# ---------------------------------------------------------------------------
# Auth
# ---------------------------------------------------------------------------

class UserCreate(BaseModel):
    email: EmailStr
    password: str = Field(..., min_length=8, max_length=128)
    full_name: str = Field(..., min_length=1, max_length=200)
    phone: Optional[str] = Field(None, max_length=20)
    role: UserRole = UserRole.MEMBER

    @field_validator("password")
    @classmethod
    def password_complexity(cls, v: str) -> str:
        if not any(c.isupper() for c in v):
            raise ValueError("Password must contain at least one uppercase letter")
        if not any(c.islower() for c in v):
            raise ValueError("Password must contain at least one lowercase letter")
        if not any(c.isdigit() for c in v):
            raise ValueError("Password must contain at least one digit")
        return v


class UserLogin(BaseModel):
    email: EmailStr
    password: str


class TokenResponse(BaseModel):
    access_token: str
    refresh_token: str
    token_type: str = "bearer"
    expires_in: int


class ChangePasswordRequest(BaseModel):
    current_password: str
    new_password: str = Field(..., min_length=8, max_length=128)

    @field_validator("new_password")
    @classmethod
    def password_complexity(cls, v: str) -> str:
        if not any(c.isupper() for c in v):
            raise ValueError("Password must contain at least one uppercase letter")
        if not any(c.islower() for c in v):
            raise ValueError("Password must contain at least one lowercase letter")
        if not any(c.isdigit() for c in v):
            raise ValueError("Password must contain at least one digit")
        return v


class UserResponse(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    email: str
    full_name: str
    phone: Optional[str] = None
    role: UserRole
    tenant_id: str
    is_active: bool
    created_at: datetime


# ---------------------------------------------------------------------------
# Accounts
# ---------------------------------------------------------------------------

class AccountCreate(BaseModel):
    account_type: AccountType
    name: str = Field(..., min_length=1, max_length=200)
    initial_deposit: Decimal = Field(default=Decimal("0.00"), ge=0)
    currency: str = Field(default="USD", max_length=3)


class AccountResponse(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    account_number: str
    account_type: AccountType
    name: str
    balance: Decimal
    available_balance: Decimal
    currency: str
    status: AccountStatus
    user_id: int
    tenant_id: str
    created_at: datetime
    updated_at: datetime


# ---------------------------------------------------------------------------
# Transactions
# ---------------------------------------------------------------------------

class TransactionCreate(BaseModel):
    transaction_type: TransactionType
    amount: Decimal = Field(..., gt=0, max_digits=15, decimal_places=2)
    from_account_id: Optional[int] = None
    to_account_id: Optional[int] = None
    description: Optional[str] = Field(None, max_length=500)
    reference: Optional[str] = Field(None, max_length=100)

    @field_validator("amount")
    @classmethod
    def amount_positive(cls, v: Decimal) -> Decimal:
        if v <= 0:
            raise ValueError("Amount must be positive")
        return v


class TransactionResponse(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    transaction_type: TransactionType
    amount: Decimal
    from_account_id: Optional[int] = None
    to_account_id: Optional[int] = None
    description: Optional[str] = None
    reference: Optional[str] = None
    status: str
    tenant_id: str
    created_at: datetime


# ---------------------------------------------------------------------------
# Loans
# ---------------------------------------------------------------------------

class LoanApplication(BaseModel):
    loan_type: LoanType
    amount: Decimal = Field(..., gt=0, max_digits=15, decimal_places=2)
    term_months: int = Field(..., ge=1, le=360)
    purpose: str = Field(..., min_length=1, max_length=500)
    annual_income: Decimal = Field(..., gt=0, max_digits=15, decimal_places=2)
    employment_status: str = Field(..., max_length=50)
    disbursement_account_id: int


class LoanPaymentRequest(BaseModel):
    amount: Decimal = Field(..., gt=0, max_digits=15, decimal_places=2)
    from_account_id: int


class LoanResponse(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    loan_type: LoanType
    principal: Decimal
    interest_rate: Decimal
    term_months: int
    monthly_payment: Decimal
    remaining_balance: Decimal
    status: LoanStatus
    purpose: str
    disbursement_account_id: int
    user_id: int
    tenant_id: str
    next_payment_date: Optional[date] = None
    created_at: datetime
    updated_at: datetime


class LoanScheduleEntry(BaseModel):
    payment_number: int
    due_date: date
    principal_amount: Decimal
    interest_amount: Decimal
    total_payment: Decimal
    remaining_balance: Decimal


# ---------------------------------------------------------------------------
# Admin / Dashboard
# ---------------------------------------------------------------------------

class DashboardStats(BaseModel):
    total_members: int
    total_accounts: int
    total_deposits: Decimal
    total_loans_outstanding: Decimal
    active_loans: int
    pending_loan_applications: int
    transactions_today: int
    new_members_this_month: int


class AuditLogEntry(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    user_id: Optional[int] = None
    tenant_id: str
    action: str
    resource: str
    resource_id: Optional[str] = None
    ip_address: Optional[str] = None
    user_agent: Optional[str] = None
    details: Optional[str] = None
    created_at: datetime


class PaginatedResponse(BaseModel):
    items: List[BaseModel] = []
    total: int
    page: int
    page_size: int
    pages: int
