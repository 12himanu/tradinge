"""`POST /v1/analyze`: signing, streaming, busy slots and path guards (P4D-01)."""

from __future__ import annotations

import json
from collections.abc import Callable
from pathlib import Path
from typing import Any

import pytest
from fastapi import FastAPI
from fastapi.testclient import TestClient

from ai_engine.api.analyze import Slots
from tests.analysis_helpers import request_for
from tests.conftest import signed_headers
from tests.fixtures import synthetic as syn


def analysis_payload(**overrides: Any) -> dict[str, Any]:
    request = request_for(syn.ascending_triangle(), **overrides)
    payload: dict[str, Any] = json.loads(request.model_dump_json())
    return payload


def post(client: TestClient, payload: dict[str, Any]) -> Any:
    body = json.dumps(payload).encode()
    return client.post(
        "/v1/analyze",
        content=body,
        headers={**signed_headers(body), "Content-Type": "application/json"},
    )


def events(response: Any) -> list[dict[str, Any]]:
    return [json.loads(line) for line in response.text.splitlines() if line.strip()]


def test_streams_accepted_stages_and_a_result(client: TestClient) -> None:
    response = post(client, analysis_payload())
    assert response.status_code == 200
    assert response.headers["content-type"].startswith("application/x-ndjson")

    stream = events(response)
    assert stream[0]["event"] == "accepted"
    assert stream[0]["analysis_id"] == "test"
    assert stream[-1]["event"] == "result"

    stages = [event["stage"] for event in stream if event["event"] == "stage"]
    assert stages == [
        "indicators",
        "structure",
        "technical",
        "smc",
        "patterns",
        "base_rates",
        "scoring",
        "risk",
        "analyst",
        "report",
    ]
    assert all(0 < event["progress"] <= 1 for event in stream if event["event"] == "stage")


def test_the_result_is_a_complete_analysis(client: TestClient) -> None:
    result = events(post(client, analysis_payload()))[-1]["data"]

    assert result["tier"] == "A"
    assert result["status"] == "completed"
    assert result["decision"]["action"]
    assert result["setup_score"] is not None
    assert result["evidence"]
    assert result["report_markdown"]
    assert result["engine_version"]


def test_unsigned_requests_are_rejected(client: TestClient) -> None:
    response = client.post("/v1/analyze", json=analysis_payload())
    assert response.status_code == 401
    assert response.json()["error"]["code"] == "UNAUTHORIZED"


def test_a_tampered_body_is_rejected(client: TestClient) -> None:
    body = json.dumps(analysis_payload()).encode()
    headers = signed_headers(body)
    tampered = json.dumps(analysis_payload(analysis_id="someone-elses")).encode()
    response = client.post(
        "/v1/analyze", content=tampered, headers={**headers, "Content-Type": "application/json"}
    )
    assert response.status_code == 401


def test_an_invalid_payload_is_a_422(client: TestClient) -> None:
    body = json.dumps({"analysis_id": "x"}).encode()
    response = client.post(
        "/v1/analyze", content=body, headers={**signed_headers(body), "Content-Type": "application/json"}
    )
    assert response.status_code == 422


def test_too_few_bars_still_streams_a_tier_c_result(client: TestClient) -> None:
    payload = analysis_payload()
    payload["bars"] = payload["bars"][:20]
    stream = events(post(client, payload))

    assert stream[-1]["event"] == "result"
    result = stream[-1]["data"]
    assert result["tier"] == "C"
    assert result["status"] == "partial"
    assert result["decision"]["limited"] is True


def test_a_failing_run_ends_with_an_error_event(client: TestClient) -> None:
    """A flat series cannot produce an ATR; the stream must say so rather than hang."""
    payload = analysis_payload()
    for bar in payload["bars"]:
        bar["open"] = bar["high"] = bar["low"] = bar["close"] = 100.0
    stream = events(post(client, payload))

    assert stream[-1]["event"] == "error"
    assert stream[-1]["error"]["code"] == "INSUFFICIENT_DATA"


def test_busy_engine_answers_503_with_a_retry_after(app_factory: Callable[..., FastAPI]) -> None:
    app = app_factory()
    app.state.analysis_slots = Slots(1)
    assert app.state.analysis_slots.try_acquire() is True  # occupy the only slot

    with TestClient(app) as client:
        response = post(client, analysis_payload())

    assert response.status_code == 503
    body = response.json()["error"]
    assert body["code"] == "ENGINE_BUSY"
    assert body["details"]["retry_after_seconds"] > 0


def test_slots_are_released_after_a_run(client: TestClient) -> None:
    slots: Slots = client.app.state.analysis_slots  # type: ignore[attr-defined]
    before = slots.in_use
    post(client, analysis_payload())
    assert slots.in_use == before


def test_an_image_path_outside_the_storage_root_is_refused(client: TestClient) -> None:
    response = post(client, analysis_payload(image_path="../../etc/passwd"))
    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_IMAGE_PATH"


def test_a_missing_image_is_refused(client: TestClient) -> None:
    response = post(client, analysis_payload(image_path="charts/nope.png"))
    assert response.status_code == 422
    assert response.json()["error"]["code"] == "IMAGE_NOT_FOUND"


def test_an_existing_image_inside_the_root_passes_the_guard(
    app_factory: Callable[..., FastAPI], tmp_path: Path
) -> None:
    (tmp_path / "charts").mkdir()
    (tmp_path / "charts" / "chart.png").write_bytes(b"not really a png, but it exists")

    with TestClient(app_factory()) as client:
        stream = events(post(client, analysis_payload(image_path="charts/chart.png")))

    # Vision arrives in 4B; for now the path is accepted and the Tier A pipeline runs as usual.
    assert stream[-1]["event"] == "result"


@pytest.mark.parametrize("limit", [1, 4])
def test_slots_count_up_and_down(limit: int) -> None:
    slots = Slots(limit)
    assert all(slots.try_acquire() for _ in range(limit))
    assert slots.try_acquire() is False
    assert slots.in_use == limit
    slots.release()
    assert slots.try_acquire() is True
