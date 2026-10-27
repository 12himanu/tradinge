"""Create local .env files with generated secrets.

    py infra/scripts/init_env.py

- Root .env (Docker Compose): created from .env.example; blank secrets are generated.
- backend/.env (Laravel): created from backend/.env.example; APP_KEY is kept if it already exists;
  shared values (DB password, engine secret, owner account) are copied from the root .env.

Safe to re-run: existing non-empty values are never overwritten. Secrets are not printed.
"""

from __future__ import annotations

import base64
import secrets
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

GENERATED = {
    "POSTGRES_PASSWORD": lambda: secrets.token_hex(16),
    "ENGINE_HMAC_SECRET": lambda: secrets.token_hex(32),
    "OWNER_PASSWORD": lambda: secrets.token_urlsafe(12),
}
SHARED_WITH_BACKEND = {
    "DB_PASSWORD": "POSTGRES_PASSWORD",
    "DB_DATABASE": "POSTGRES_DB",
    "DB_USERNAME": "POSTGRES_USER",
    "DB_PORT": "POSTGRES_PORT",
    "REDIS_PORT": "REDIS_PORT",
    "ENGINE_HMAC_SECRET": "ENGINE_HMAC_SECRET",
    "OWNER_NAME": "OWNER_NAME",
    "OWNER_EMAIL": "OWNER_EMAIL",
    "OWNER_PASSWORD": "OWNER_PASSWORD",
}


def read_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.exists():
        return values
    for line in path.read_text(encoding="utf-8").splitlines():
        if "=" in line and not line.lstrip().startswith("#"):
            key, value = line.split("=", 1)
            values[key.strip()] = value.strip()
    return values


def render(template: Path, values: dict[str, str]) -> str:
    """Rewrite the template line by line, filling keys that have a value."""
    lines = []
    for line in template.read_text(encoding="utf-8").splitlines():
        if "=" in line and not line.lstrip().startswith("#"):
            key = line.split("=", 1)[0].strip()
            if values.get(key):
                line = f"{key}={values[key]}"
        lines.append(line)
    return "\n".join(lines) + "\n"


def main() -> None:
    root_env_path = ROOT / ".env"
    root = {**read_env(ROOT / ".env.example"), **read_env(root_env_path)}
    for key, generate in GENERATED.items():
        if not root.get(key):
            root[key] = generate()
    root_env_path.write_text(render(ROOT / ".env.example", root), encoding="utf-8", newline="\n")

    backend_env_path = ROOT / "backend" / ".env"
    backend = {**read_env(ROOT / "backend" / ".env.example"), **read_env(backend_env_path)}
    if not backend.get("APP_KEY"):
        backend["APP_KEY"] = "base64:" + base64.b64encode(secrets.token_bytes(32)).decode()
    for backend_key, root_key in SHARED_WITH_BACKEND.items():
        if root.get(root_key):
            backend[backend_key] = root[root_key]
    backend_env_path.write_text(
        render(ROOT / "backend" / ".env.example", backend), encoding="utf-8", newline="\n"
    )

    print(f"wrote {root_env_path.relative_to(ROOT)} and {backend_env_path.relative_to(ROOT)}")
    print(f"owner login: {root['OWNER_EMAIL']} (password is OWNER_PASSWORD in .env)")


if __name__ == "__main__":
    main()
