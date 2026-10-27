"""The base every contract model shares.

Its own module so schemas can import it without importing each other: `report.py` needs `Strict`
and `analysis.py` needs `AnalystNarrative`, which would otherwise be a cycle.
"""

from __future__ import annotations

from pydantic import BaseModel, ConfigDict


class Strict(BaseModel):
    """Base for contract models: unknown fields are a bug, not something to ignore."""

    model_config = ConfigDict(extra="forbid")
