"""Tenant resolution middleware and dependency.

Every authenticated request carries a ``tenant_id`` inside the JWT.  This
module provides:

* A Starlette middleware that attaches ``request.state.tenant_id`` early in
  the request lifecycle (useful for audit logging before the route handler
  runs).
* A FastAPI dependency (``get_tenant_id``) that route handlers use to
  obtain the resolved tenant id.
"""

from __future__ import annotations

from typing import Any, Dict, Optional

from fastapi import Depends, HTTPException, Request, status
from starlette.middleware.base import BaseHTTPMiddleware, RequestResponseEndpoint
from starlette.responses import Response

from middleware.auth import get_current_user


# ---------------------------------------------------------------------------
# Starlette middleware - runs on every request
# ---------------------------------------------------------------------------


class TenantMiddleware(BaseHTTPMiddleware):
    """Extract ``X-Tenant-ID`` header (if present) and store on request state.

    For authenticated requests the JWT ``tenant_id`` claim takes precedence
    (set by ``get_current_user``).  This middleware is a convenience for
    pre-auth tenant resolution, e.g. in public endpoints.
    """

    async def dispatch(
        self, request: Request, call_next: RequestResponseEndpoint
    ) -> Response:
        # Default: try header
        header_tenant = request.headers.get("X-Tenant-ID")
        if header_tenant:
            request.state.tenant_id = header_tenant
        elif not hasattr(request.state, "tenant_id"):
            request.state.tenant_id = None

        response = await call_next(request)
        return response


# ---------------------------------------------------------------------------
# FastAPI dependency
# ---------------------------------------------------------------------------


async def get_tenant_id(
    request: Request,
    current_user: Dict[str, Any] = Depends(get_current_user),
) -> str:
    """Return the authenticated user's tenant_id.

    Raises 400 if no tenant could be resolved.
    """
    tenant_id: Optional[str] = current_user.get("tenant_id")
    if not tenant_id:
        tenant_id = getattr(request.state, "tenant_id", None)
    if not tenant_id:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Tenant could not be resolved. Provide X-Tenant-ID header or ensure JWT contains tenant_id.",
        )
    return tenant_id
