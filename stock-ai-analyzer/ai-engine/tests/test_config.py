from __future__ import annotations

import re
from collections.abc import Callable
from pathlib import Path

import pytest
from fastapi import FastAPI
from pydantic import SecretStr, ValidationError

from ai_engine.errors import ConfigurationError
from ai_engine.models.ollama import InstalledModel
from ai_engine.models.registry import ModelSpec, load_profiles, normalize_tag, resolve_status
from ai_engine.settings import PROJECT_ROOT, Settings
from ai_engine.versioning import config_hash, engine_version

CONFIG_DIR = PROJECT_ROOT / "config"


def test_short_hmac_secret_is_rejected() -> None:
    with pytest.raises(ValidationError, match="at least 32"):
        Settings(hmac_secret=SecretStr("too-short"))


def test_unknown_model_profile_fails_fast(app_factory: Callable[..., FastAPI]) -> None:
    with pytest.raises(ConfigurationError, match="Unknown model profile"):
        app_factory(model_profile="gpu_999gb")


def test_all_profiles_load() -> None:
    profiles = load_profiles(CONFIG_DIR)
    assert set(profiles) == {"gpu_4gb", "gpu_8gb", "gpu_16gb", "cpu"}
    assert profiles["gpu_4gb"].vision.tag == "qwen2.5vl:3b"


def test_invalid_models_file_raises(tmp_path: Path) -> None:
    (tmp_path / "models.yaml").write_text("profiles:\n  x:\n    vision: {tag: a}\n", encoding="utf-8")
    with pytest.raises(ConfigurationError):
        load_profiles(tmp_path)


def test_tag_normalization() -> None:
    assert normalize_tag("llava") == "llava:latest"
    assert normalize_tag("qwen3:4b") == "qwen3:4b"


@pytest.mark.parametrize(
    ("digest", "installed", "expected"),
    [
        (None, [InstalledModel("qwen3:4b", "b" * 64, 1)], "present"),
        ("sha256:" + "b" * 12, [InstalledModel("qwen3:4b", "b" * 64, 1)], "present"),
        ("c" * 64, [InstalledModel("qwen3:4b", "b" * 64, 1)], "digest_mismatch"),
        (None, [InstalledModel("qwen3:8b", "b" * 64, 1)], "missing"),
        (None, None, "unknown"),
    ],
)
def test_resolve_status(digest: str | None, installed: list[InstalledModel] | None, expected: str) -> None:
    status = resolve_status(ModelSpec("text", "qwen3:4b", digest), installed)
    assert status.status == expected


def test_engine_version_format() -> None:
    assert re.fullmatch(r"\d+\.\d+\.\d+\+cfg\.[0-9a-f]{8}", engine_version(CONFIG_DIR))


def test_config_hash_ignores_line_endings_but_not_content(tmp_path: Path) -> None:
    (tmp_path / "a.yaml").write_bytes(b"x: 1\n")
    lf = config_hash(tmp_path)
    (tmp_path / "a.yaml").write_bytes(b"x: 1\r\n")
    assert config_hash(tmp_path) == lf
    (tmp_path / "a.yaml").write_bytes(b"x: 2\n")
    assert config_hash(tmp_path) != lf
