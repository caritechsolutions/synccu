"""Application configuration loaded from environment variables."""

from __future__ import annotations

from functools import lru_cache
from typing import List

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Central configuration sourced from .env / environment."""

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        case_sensitive=False,
    )

    # Database
    DATABASE_URL: str = "mysql+pymysql://synccu_user:password@localhost:3306/synccu"

    # JWT
    JWT_SECRET: str = "your-secret-key-min-32-chars-long"
    JWT_ALGORITHM: str = "HS256"
    JWT_EXPIRY_MINUTES: int = 60

    # Encryption
    ENCRYPTION_KEY: str = "your-32-byte-encryption-key-here"

    # Redis
    REDIS_URL: str = "redis://localhost:6379/0"

    # CORS
    CORS_ORIGINS: str = "http://localhost,http://localhost:3000"

    # Rate limiting
    RATE_LIMIT: str = "60/minute"

    @property
    def cors_origin_list(self) -> List[str]:
        return [o.strip() for o in self.CORS_ORIGINS.split(",") if o.strip()]

    @property
    def async_database_url(self) -> str:
        """Return an asyncio-compatible database URL.

        Converts ``mysql+pymysql://`` to ``mysql+aiomysql://`` so that
        SQLAlchemy's async engine can use it.  If the URL already contains
        an async driver it is returned unchanged.
        """
        if "aiomysql" in self.DATABASE_URL:
            return self.DATABASE_URL
        return self.DATABASE_URL.replace("mysql+pymysql://", "mysql+aiomysql://")


@lru_cache()
def get_settings() -> Settings:
    """Return a cached Settings instance."""
    return Settings()
