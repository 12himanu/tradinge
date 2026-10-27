from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Literal

import yaml
from pydantic import BaseModel, ConfigDict, ValidationError

from ai_engine.errors import ConfigurationError
from ai_engine.models.ollama import InstalledModel
from ai_engine.schemas.models import ModelStatus

ModelRole = Literal["vision", "text"]


class _SpecConfig(BaseModel):
    model_config = ConfigDict(extra="forbid")
    tag: str
    digest: str | None = None
    num_ctx: int | None = None


class _ProfileConfig(BaseModel):
    model_config = ConfigDict(extra="forbid")
    vision: _SpecConfig
    text: _SpecConfig


class _ModelsFile(BaseModel):
    model_config = ConfigDict(extra="forbid")
    profiles: dict[str, _ProfileConfig]


@dataclass(frozen=True, slots=True)
class ModelSpec:
    role: ModelRole
    tag: str
    digest: str | None
    num_ctx: int | None = None
    """Context window to request. Sized per hardware tier: a window larger than the work needs
    costs KV cache that a small GPU pays for by spilling layers to the CPU. On the 4 GB profile a
    4k window runs the analyst at roughly twice the tokens per second of a 16k one."""


@dataclass(frozen=True, slots=True)
class ModelProfile:
    name: str
    vision: ModelSpec
    text: ModelSpec

    def specs(self) -> tuple[ModelSpec, ModelSpec]:
        return (self.vision, self.text)


def load_profiles(config_dir: Path) -> dict[str, ModelProfile]:
    path = config_dir / "models.yaml"
    try:
        raw = yaml.safe_load(path.read_text(encoding="utf-8"))
        parsed = _ModelsFile.model_validate(raw)
    except (OSError, yaml.YAMLError, ValidationError) as exc:
        raise ConfigurationError(f"Invalid model configuration in {path}: {exc}") from exc

    return {
        name: ModelProfile(
            name=name,
            vision=ModelSpec("vision", profile.vision.tag, profile.vision.digest, profile.vision.num_ctx),
            text=ModelSpec("text", profile.text.tag, profile.text.digest, profile.text.num_ctx),
        )
        for name, profile in parsed.profiles.items()
    }


def normalize_tag(tag: str) -> str:
    """Ollama treats a tag without a version as `:latest`."""
    return tag if ":" in tag else f"{tag}:latest"


def resolve_status(spec: ModelSpec, installed: list[InstalledModel] | None) -> ModelStatus:
    """Compare a configured model with what Ollama reports; `installed=None` means Ollama is unreachable."""
    expected = spec.digest.removeprefix("sha256:") if spec.digest else None
    if installed is None:
        return ModelStatus(
            role=spec.role, tag=spec.tag, expected_digest=expected, installed_digest=None, status="unknown"
        )

    wanted = normalize_tag(spec.tag)
    match = next((m for m in installed if normalize_tag(m.name) == wanted), None)
    if match is None:
        return ModelStatus(
            role=spec.role, tag=spec.tag, expected_digest=expected, installed_digest=None, status="missing"
        )

    installed_digest = match.digest.removeprefix("sha256:")
    status: Literal["present", "digest_mismatch"] = (
        "present" if expected is None or installed_digest.startswith(expected) else "digest_mismatch"
    )
    return ModelStatus(
        role=spec.role,
        tag=spec.tag,
        expected_digest=expected,
        installed_digest=installed_digest,
        status=status,
    )
