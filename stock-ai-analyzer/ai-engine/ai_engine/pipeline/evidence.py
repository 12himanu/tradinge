"""The evidence store (docs/04-ai-agent-workflow.md §1).

Every number the report may contain passes through here first. The analyst cites items by
id, and the Phase 4C grounding guardrail rejects any number that is not in `allowed_values`.
"""

from __future__ import annotations

from datetime import datetime

from ai_engine.schemas.analysis import DataTier, EvidenceItem, EvidenceKind


class EvidenceStore:
    """Append-only collection of facts with sequential ids (E1, E2, ...)."""

    def __init__(self, tier: DataTier = "A", as_of: datetime | None = None) -> None:
        self._items: list[EvidenceItem] = []
        self._by_key: dict[str, EvidenceItem] = {}
        self.tier: DataTier = tier
        self.as_of = as_of

    def add(
        self,
        kind: EvidenceKind,
        key: str,
        text: str,
        *,
        value: float | None = None,
        value_high: float | None = None,
        unit: str = "none",
        source: str = "engine",
        weight: float = 0.0,
        tier: DataTier | None = None,
        as_of: datetime | None = None,
    ) -> EvidenceItem:
        """Register one fact. Re-using a key replaces nothing: keys are expected to be unique."""
        if key in self._by_key:
            raise ValueError(f"evidence key {key!r} already registered as {self._by_key[key].id}")
        item = EvidenceItem(
            id=f"E{len(self._items) + 1}",
            kind=kind,
            key=key,
            value=value,
            value_high=value_high,
            unit=unit,
            source=source,
            tier=tier or self.tier,
            as_of=as_of or self.as_of,
            text=text,
            weight=weight,
        )
        self._items.append(item)
        self._by_key[key] = item
        return item

    def add_price(self, key: str, text: str, value: float, **kwargs: object) -> EvidenceItem:
        return self.add("level", key, text, value=value, unit="price", **kwargs)  # type: ignore[arg-type]

    def add_indicator(
        self, key: str, text: str, value: float | None, *, unit: str = "none", **kwargs: object
    ) -> EvidenceItem:
        return self.add("indicator", key, text, value=value, unit=unit, **kwargs)  # type: ignore[arg-type]

    def get(self, key: str) -> EvidenceItem | None:
        return self._by_key.get(key)

    def id_for(self, key: str) -> str | None:
        item = self._by_key.get(key)
        return item.id if item else None

    def ids_for(self, *keys: str) -> list[str]:
        return [item.id for key in keys if (item := self._by_key.get(key)) is not None]

    def by_id(self, evidence_id: str) -> EvidenceItem | None:
        return next((item for item in self._items if item.id == evidence_id), None)

    def of_kind(self, kind: EvidenceKind) -> list[EvidenceItem]:
        return [item for item in self._items if item.kind == kind]

    @property
    def items(self) -> list[EvidenceItem]:
        return list(self._items)

    def __len__(self) -> int:
        return len(self._items)

    def allowed_values(self) -> set[float]:
        """Every registered number, for the Phase 4C grounding check."""
        values: set[float] = set()
        for item in self._items:
            if item.value is not None:
                values.add(item.value)
            if item.value_high is not None:
                values.add(item.value_high)
        return values

    def top(self, limit: int) -> list[EvidenceItem]:
        """The highest-weight items, ties broken by insertion order (`limit` <= 0 means all)."""
        if limit <= 0:
            return list(self._items)
        ranked = sorted(self._items, key=lambda item: (-item.weight, int(item.id[1:])))
        chosen = {item.id for item in ranked[:limit]}
        return [item for item in self._items if item.id in chosen]
