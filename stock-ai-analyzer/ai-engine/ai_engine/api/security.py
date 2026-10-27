from __future__ import annotations

import hashlib
import hmac
import time

from fastapi import Request

from ai_engine.api.deps import get_settings
from ai_engine.errors import EngineError


def sign(secret: str, timestamp: str, body: bytes) -> str:
    """hex(HMAC-SHA256(secret, timestamp + "." + body)) — the Laravel client computes the same."""
    message = timestamp.encode() + b"." + body
    return hmac.new(secret.encode(), message, hashlib.sha256).hexdigest()


def _unauthorized(message: str) -> EngineError:
    return EngineError("UNAUTHORIZED", message, status_code=401)


async def verify_signature(request: Request) -> None:
    """Dependency for internal routes: rejects unsigned, tampered or replayed-too-late requests."""
    settings = get_settings(request)
    timestamp = request.headers.get("X-Timestamp")
    signature = request.headers.get("X-Signature")
    if not timestamp or not signature:
        raise _unauthorized("Missing X-Timestamp or X-Signature header")

    try:
        sent_at = int(timestamp)
    except ValueError as exc:
        raise _unauthorized("X-Timestamp must be Unix seconds") from exc

    if abs(int(time.time()) - sent_at) > settings.hmac_max_skew_seconds:
        raise _unauthorized("Request timestamp outside the allowed window")

    expected = sign(settings.hmac_secret.get_secret_value(), timestamp, await request.body())
    if not hmac.compare_digest(expected, signature.strip().lower()):
        raise _unauthorized("Invalid signature")
