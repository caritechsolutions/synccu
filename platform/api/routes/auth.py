"""Authentication routes: register, login, refresh, logout, change-password."""

from __future__ import annotations

import uuid
from datetime import datetime, timezone
from typing import Any, Dict

from fastapi import APIRouter, Depends, HTTPException, Request, status
from sqlalchemy import Column, DateTime, Integer, String, Boolean, func, select, text
from sqlalchemy.ext.asyncio import AsyncSession

from config import Settings, get_settings
from database import Base, TenantMixin, TimestampMixin, get_db
from middleware.auth import (
    create_access_token,
    create_refresh_token,
    decode_token,
    get_current_user,
    hash_password,
    verify_password,
)
from models.schemas import (
    ChangePasswordRequest,
    TokenResponse,
    UserCreate,
    UserLogin,
    UserResponse,
)

router = APIRouter(prefix="/auth", tags=["Authentication"])


# ---------------------------------------------------------------------------
# ORM model (lives here to keep the example self-contained)
# ---------------------------------------------------------------------------


class User(Base, TenantMixin, TimestampMixin):
    __tablename__ = "users"

    id = Column(Integer, primary_key=True, autoincrement=True)
    email = Column(String(255), unique=True, nullable=False, index=True)
    hashed_password = Column(String(255), nullable=False)
    full_name = Column(String(200), nullable=False)
    phone = Column(String(20), nullable=True)
    role = Column(String(20), nullable=False, server_default=text("'member'"))
    is_active = Column(Boolean, nullable=False, server_default=text("1"))


# ---------------------------------------------------------------------------
# Token blacklist (in-memory for demo; use Redis in production)
# ---------------------------------------------------------------------------

_blacklisted_tokens: set[str] = set()


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _build_token_pair(user: User, settings: Settings) -> TokenResponse:
    claims = {
        "sub": str(user.id),
        "email": user.email,
        "role": user.role,
        "tenant_id": user.tenant_id,
    }
    access = create_access_token(claims, settings)
    refresh = create_refresh_token(claims, settings)
    return TokenResponse(
        access_token=access,
        refresh_token=refresh,
        expires_in=settings.JWT_EXPIRY_MINUTES * 60,
    )


# ---------------------------------------------------------------------------
# Routes
# ---------------------------------------------------------------------------


@router.post(
    "/register",
    response_model=TokenResponse,
    status_code=status.HTTP_201_CREATED,
    summary="Register a new user",
)
async def register(
    body: UserCreate,
    request: Request,
    db: AsyncSession = Depends(get_db),
    settings: Settings = Depends(get_settings),
) -> TokenResponse:
    # Check for duplicate email
    result = await db.execute(select(User).where(User.email == body.email))
    if result.scalar_one_or_none() is not None:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="Email already registered",
        )

    # Resolve tenant - from header or generate new
    tenant_id = request.headers.get("X-Tenant-ID") or uuid.uuid4().hex[:12]

    user = User(
        email=body.email,
        hashed_password=hash_password(body.password),
        full_name=body.full_name,
        phone=body.phone,
        role=body.role.value,
        tenant_id=tenant_id,
    )
    db.add(user)
    await db.flush()
    await db.refresh(user)

    return _build_token_pair(user, settings)


@router.post("/login", response_model=TokenResponse, summary="Authenticate user")
async def login(
    body: UserLogin,
    db: AsyncSession = Depends(get_db),
    settings: Settings = Depends(get_settings),
) -> TokenResponse:
    result = await db.execute(select(User).where(User.email == body.email))
    user = result.scalar_one_or_none()

    if user is None or not verify_password(body.password, user.hashed_password):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid email or password",
        )

    if not user.is_active:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Account is deactivated",
        )

    return _build_token_pair(user, settings)


@router.post("/refresh", response_model=TokenResponse, summary="Refresh access token")
async def refresh_token(
    request: Request,
    db: AsyncSession = Depends(get_db),
    settings: Settings = Depends(get_settings),
) -> TokenResponse:
    auth_header = request.headers.get("Authorization", "")
    if not auth_header.startswith("Bearer "):
        raise HTTPException(status_code=401, detail="Missing refresh token")

    token = auth_header.split(" ", 1)[1]
    if token in _blacklisted_tokens:
        raise HTTPException(status_code=401, detail="Token has been revoked")

    payload = decode_token(token, settings)
    if payload.get("type") != "refresh":
        raise HTTPException(status_code=401, detail="Not a refresh token")

    user_id = payload.get("sub")
    result = await db.execute(select(User).where(User.id == int(user_id)))
    user = result.scalar_one_or_none()
    if user is None or not user.is_active:
        raise HTTPException(status_code=401, detail="User not found or inactive")

    # Blacklist the old refresh token (rotate)
    _blacklisted_tokens.add(token)

    return _build_token_pair(user, settings)


@router.post("/logout", status_code=status.HTTP_204_NO_CONTENT, summary="Logout")
async def logout(
    request: Request,
    current_user: Dict[str, Any] = Depends(get_current_user),
) -> None:
    auth_header = request.headers.get("Authorization", "")
    if auth_header.startswith("Bearer "):
        _blacklisted_tokens.add(auth_header.split(" ", 1)[1])


@router.post("/change-password", status_code=status.HTTP_200_OK, summary="Change password")
async def change_password(
    body: ChangePasswordRequest,
    current_user: Dict[str, Any] = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
) -> Dict[str, str]:
    user_id = int(current_user["sub"])
    result = await db.execute(select(User).where(User.id == user_id))
    user = result.scalar_one_or_none()

    if user is None:
        raise HTTPException(status_code=404, detail="User not found")

    if not verify_password(body.current_password, user.hashed_password):
        raise HTTPException(status_code=400, detail="Current password is incorrect")

    user.hashed_password = hash_password(body.new_password)
    await db.flush()

    return {"message": "Password changed successfully"}
