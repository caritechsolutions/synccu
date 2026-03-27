"""SQLAlchemy async engine, session factory, and declarative Base."""

from __future__ import annotations

from typing import AsyncGenerator

from sqlalchemy import Column, DateTime, Integer, String, func
from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker, create_async_engine
from sqlalchemy.orm import DeclarativeBase

from config import get_settings

settings = get_settings()

engine = create_async_engine(
    settings.async_database_url,
    echo=False,
    pool_pre_ping=True,
    pool_size=20,
    max_overflow=10,
)

async_session_factory = async_sessionmaker(
    engine,
    class_=AsyncSession,
    expire_on_commit=False,
)


class Base(DeclarativeBase):
    """Shared declarative base for all ORM models."""
    pass


class TenantMixin:
    """Mixin that adds tenant_id scoping to any model."""

    tenant_id = Column(String(36), nullable=False, index=True)


class TimestampMixin:
    """Mixin that adds created_at / updated_at columns."""

    created_at = Column(DateTime, server_default=func.now(), nullable=False)
    updated_at = Column(
        DateTime, server_default=func.now(), onupdate=func.now(), nullable=False
    )


class BaseModel(Base, TimestampMixin):
    """Abstract base providing id + timestamps."""

    __abstract__ = True

    id = Column(Integer, primary_key=True, autoincrement=True)


async def get_db() -> AsyncGenerator[AsyncSession, None]:
    """FastAPI dependency that yields an async database session."""
    async with async_session_factory() as session:
        try:
            yield session
            await session.commit()
        except Exception:
            await session.rollback()
            raise
        finally:
            await session.close()


async def init_db() -> None:
    """Create all tables (used during startup for development)."""
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
