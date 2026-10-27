from __future__ import annotations

from collections.abc import Callable
from pathlib import Path

from fastapi import FastAPI
from fastapi.testclient import TestClient

from tests.conftest import DIGEST_VISION, ollama_handler, signed_headers, unreachable_handler


def test_livez_needs_no_dependencies(app_factory: Callable[..., FastAPI]) -> None:
    client = TestClient(app_factory(unreachable_handler))
    response = client.get("/livez")
    assert response.status_code == 200
    assert response.json() == {"status": "alive"}


def test_health_ok_when_ollama_and_models_present(client: TestClient) -> None:
    body = client.get("/health").json()
    assert body["status"] == "ok"
    assert body["model_profile"] == "gpu_4gb"
    assert body["ollama"] == {"reachable": True, "version": "0.12.0", "error": None}
    assert {m["tag"]: m["status"] for m in body["models"]} == {
        "qwen2.5vl:3b": "present",
        "qwen3:4b": "present",
    }
    assert body["storage_readable"] is True
    assert body["engine_version"].startswith("0.1.0+cfg.")


def test_health_degraded_when_ollama_unreachable(app_factory: Callable[..., FastAPI]) -> None:
    body = TestClient(app_factory(unreachable_handler)).get("/health").json()
    assert body["status"] == "degraded"
    assert body["ollama"]["reachable"] is False
    assert "Cannot reach Ollama" in body["ollama"]["error"]
    assert all(m["status"] == "unknown" for m in body["models"])


def test_health_degraded_when_model_missing(app_factory: Callable[..., FastAPI]) -> None:
    handler = ollama_handler([{"name": "qwen2.5vl:3b", "digest": DIGEST_VISION, "size": 1}])
    body = TestClient(app_factory(handler)).get("/health").json()
    assert body["status"] == "degraded"
    assert {m["tag"]: m["status"] for m in body["models"]}["qwen3:4b"] == "missing"


def test_health_degraded_when_storage_missing(app_factory: Callable[..., FastAPI], tmp_path: Path) -> None:
    app = app_factory(storage_root=tmp_path / "does-not-exist")
    body = TestClient(app).get("/health").json()
    assert body["status"] == "degraded"
    assert body["storage_readable"] is False


def test_models_endpoint_lists_profiles(client: TestClient) -> None:
    body = client.get("/v1/models", headers=signed_headers()).json()
    assert body["active_profile"] == "gpu_4gb"
    assert body["profiles"] == ["cpu", "gpu_16gb", "gpu_4gb", "gpu_8gb"]
    assert len(body["models"]) == 2


def test_request_id_is_echoed(client: TestClient) -> None:
    response = client.get("/health", headers={"X-Request-Id": "01J8Z3TEST"})
    assert response.headers["X-Request-Id"] == "01J8Z3TEST"
