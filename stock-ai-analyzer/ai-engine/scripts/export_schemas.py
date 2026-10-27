"""Export Pydantic models to contracts/*.schema.json for Laravel contract tests.

Usage:
    python scripts/export_schemas.py          # write files
    python scripts/export_schemas.py --check  # exit 1 if committed schemas are stale
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

from pydantic import BaseModel

from ai_engine.schemas.analysis import AnalysisRequest, AnalysisResult
from ai_engine.schemas.models import ErrorResponse, HealthResponse, ModelsResponse

CONTRACTS_DIR = Path(__file__).resolve().parent.parent / "contracts"

SCHEMAS: dict[str, type[BaseModel]] = {
    "error_response": ErrorResponse,
    "health_response": HealthResponse,
    "models_response": ModelsResponse,
    "analysis_request": AnalysisRequest,
    "analysis_result": AnalysisResult,
}


def render(model: type[BaseModel]) -> str:
    return json.dumps(model.model_json_schema(), indent=2, sort_keys=True) + "\n"


def main(argv: list[str]) -> int:
    check = "--check" in argv
    CONTRACTS_DIR.mkdir(exist_ok=True)
    stale: list[str] = []
    for name, model in SCHEMAS.items():
        path = CONTRACTS_DIR / f"{name}.schema.json"
        content = render(model)
        if check:
            if not path.exists() or path.read_text(encoding="utf-8") != content:
                stale.append(path.name)
        else:
            path.write_text(content, encoding="utf-8", newline="\n")
            print(f"wrote {path.relative_to(CONTRACTS_DIR.parent)}")
    if stale:
        print("Stale contract schemas: " + ", ".join(stale) + " — run scripts/export_schemas.py")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
