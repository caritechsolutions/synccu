"""AES-256-GCM encryption service for sensitive data at rest.

Usage::

    from services.encryption import EncryptionService
    svc = EncryptionService()            # reads key from settings
    ciphertext = svc.encrypt("SSN-123")
    plaintext  = svc.decrypt(ciphertext)
"""

from __future__ import annotations

import base64
import os
from typing import Optional

from cryptography.hazmat.primitives.ciphers.aead import AESGCM

from config import Settings, get_settings


class EncryptionService:
    """Thin wrapper around AES-256-GCM for field-level encryption."""

    # GCM recommended nonce size
    _NONCE_BYTES = 12

    def __init__(self, settings: Optional[Settings] = None) -> None:
        settings = settings or get_settings()
        raw_key = settings.ENCRYPTION_KEY.encode("utf-8")

        # Ensure exactly 32 bytes for AES-256.  If the configured value is
        # shorter we pad with zeros; if longer we truncate.  In production the
        # key MUST be a full 32-byte secret.
        key = raw_key[:32].ljust(32, b"\0")
        self._aesgcm = AESGCM(key)

    def encrypt(self, plaintext: str) -> str:
        """Encrypt *plaintext* and return a URL-safe base-64 string.

        The returned string contains the nonce prepended to the ciphertext so
        that ``decrypt`` can recover both.
        """
        nonce = os.urandom(self._NONCE_BYTES)
        ct = self._aesgcm.encrypt(nonce, plaintext.encode("utf-8"), None)
        # nonce || ciphertext -> base64
        return base64.urlsafe_b64encode(nonce + ct).decode("ascii")

    def decrypt(self, token: str) -> str:
        """Decrypt a token previously produced by ``encrypt``."""
        raw = base64.urlsafe_b64decode(token.encode("ascii"))
        nonce = raw[: self._NONCE_BYTES]
        ct = raw[self._NONCE_BYTES :]
        plaintext_bytes = self._aesgcm.decrypt(nonce, ct, None)
        return plaintext_bytes.decode("utf-8")


# Module-level singleton for convenience
_default: Optional[EncryptionService] = None


def get_encryption_service() -> EncryptionService:
    """Return (and lazily create) the module-level EncryptionService."""
    global _default
    if _default is None:
        _default = EncryptionService()
    return _default
