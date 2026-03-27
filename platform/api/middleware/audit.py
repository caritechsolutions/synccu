"""Audit logging middleware that records every HTTP request."""

from __future__ import annotations

import json
import logging
import time
from datetime import datetime, timezone
from typing import Optional

from starlette.middleware.base import BaseHTTPMiddleware, RequestResponseEndpoint
from starlette.requests import Request
from starlette.responses import Response

logger = logging.getLogger("synccu.audit")


class AuditLogMiddleware(BaseHTTPMiddleware):
    """Log every request with user, tenant, timing, and outcome metadata.

    In a production deployment this would write to a persistent audit table;
    here we emit structured JSON via the standard ``logging`` module so the
    records can be shipped to any log aggregator.
    """

    async def dispatch(
        self, request: Request, call_next: RequestResponseEndpoint
    ) -> Response:
        start = time.perf_counter()

        # Capture request metadata available before the route handler runs
        ip_address: Optional[str] = None
        if request.client:
            ip_address = request.client.host
        user_agent = request.headers.get("User-Agent", "")

        response: Optional[Response] = None
        error_detail: Optional[str] = None
        try:
            response = await call_next(request)
        except Exception as exc:
            error_detail = str(exc)
            raise
        finally:
            elapsed_ms = round((time.perf_counter() - start) * 1000, 2)

            # After the route handler runs, auth middleware may have set these
            user_id = getattr(request.state, "user_id", None)
            tenant_id = getattr(request.state, "tenant_id", None)

            status_code = response.status_code if response else 500

            log_entry = {
                "timestamp": datetime.now(timezone.utc).isoformat(),
                "method": request.method,
                "path": request.url.path,
                "query": str(request.url.query) if request.url.query else None,
                "status_code": status_code,
                "elapsed_ms": elapsed_ms,
                "user_id": user_id,
                "tenant_id": tenant_id,
                "ip_address": ip_address,
                "user_agent": user_agent,
                "error": error_detail,
            }

            if status_code >= 400:
                logger.warning(json.dumps(log_entry))
            else:
                logger.info(json.dumps(log_entry))

        return response
