from __future__ import annotations

import hashlib
from pathlib import Path

from ai_engine import __version__


def config_hash(config_dir: Path) -> str:
    """Short hash of every YAML config file; line endings normalized so Windows and Linux agree."""
    digest = hashlib.sha256()
    for path in sorted(config_dir.glob("*.yaml")):
        digest.update(path.name.encode())
        digest.update(path.read_bytes().replace(b"\r\n", b"\n"))
    return digest.hexdigest()[:8]


def engine_version(config_dir: Path) -> str:
    """Package version plus config hash, stored on every analysis for reproducibility."""
    return f"{__version__}+cfg.{config_hash(config_dir)}"
