from __future__ import annotations

import time

from fastapi.testclient import TestClient

from ai_engine.api.security import sign
from tests.conftest import TEST_SECRET, signed_headers

# Shared with backend/tests/Unit/Infrastructure/EngineRequestSignerTest.php so both sides provably agree.
CROSS_LANGUAGE_VECTOR = "148d6e7003171d607834c2cc8bed191433ada646f3cf550559d49c378bfa56fe"


def test_signature_matches_cross_language_vector() -> None:
    assert sign(TEST_SECRET, "1757680000", b'{"symbol":"HDL"}') == CROSS_LANGUAGE_VECTOR


def test_signed_request_is_accepted(client: TestClient) -> None:
    response = client.get("/v1/models", headers=signed_headers())
    assert response.status_code == 200


def test_missing_headers_are_rejected(client: TestClient) -> None:
    response = client.get("/v1/models")
    assert response.status_code == 401
    assert response.json()["error"]["code"] == "UNAUTHORIZED"


def test_wrong_secret_is_rejected(client: TestClient) -> None:
    response = client.get("/v1/models", headers=signed_headers(secret="another-secret-" + "y" * 32))
    assert response.status_code == 401


def test_stale_timestamp_is_rejected(client: TestClient) -> None:
    response = client.get("/v1/models", headers=signed_headers(timestamp=int(time.time()) - 3600))
    assert response.status_code == 401
    assert "window" in response.json()["error"]["message"]


def test_non_numeric_timestamp_is_rejected(client: TestClient) -> None:
    headers = signed_headers()
    headers["X-Timestamp"] = "yesterday"
    assert client.get("/v1/models", headers=headers).status_code == 401


def test_tampered_body_is_rejected(client: TestClient) -> None:
    headers = signed_headers(b'{"a":1}')
    response = client.request("GET", "/v1/models", headers=headers, content=b'{"a":2}')
    assert response.status_code == 401


def test_signature_is_case_insensitive(client: TestClient) -> None:
    headers = signed_headers()
    headers["X-Signature"] = headers["X-Signature"].upper()
    assert client.get("/v1/models", headers=headers).status_code == 200
