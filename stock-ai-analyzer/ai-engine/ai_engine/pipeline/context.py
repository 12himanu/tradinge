"""Mutable state carried through one analysis run."""

from __future__ import annotations

import time
from collections.abc import Callable
from dataclasses import dataclass, field

from ai_engine.analysis.indicators import IndicatorSet
from ai_engine.analysis.levels import LevelSet
from ai_engine.analysis.series import Series
from ai_engine.analysis.swings import SwingSet
from ai_engine.pipeline.config import EngineConfig, StyleProfile
from ai_engine.pipeline.evidence import EvidenceStore
from ai_engine.schemas.analysis import (
    AnalysisRequest,
    DataTier,
    PatternResult,
    SmcResult,
    StageTiming,
)

ProgressCallback = Callable[[str, float], None]
"""Called with the stage name and a 0-1 completion fraction."""


@dataclass
class AnalysisContext:
    """The working set for one analysis: inputs, intermediate results, evidence and timings."""

    request: AnalysisRequest
    config: EngineConfig
    evidence: EvidenceStore
    tier: DataTier
    data_quality: float
    series: Series | None = None
    indicators: IndicatorSet | None = None
    swings: SwingSet | None = None
    levels: LevelSet | None = None
    patterns: list[PatternResult] = field(default_factory=list)
    smc: SmcResult | None = None
    warnings: list[str] = field(default_factory=list)
    timings: list[StageTiming] = field(default_factory=list)
    progress: ProgressCallback | None = None

    @property
    def style(self) -> StyleProfile:
        return self.config.style(self.request.trading_style)

    @property
    def atr(self) -> float:
        """Latest ATR, or 0.0 when it could not be computed; callers must guard on it."""
        if self.indicators is None:
            return 0.0
        return self.indicators.last_atr or 0.0

    def warn(self, message: str) -> None:
        if message not in self.warnings:
            self.warnings.append(message)

    def stage(self, name: str, fraction: float) -> _StageTimer:
        return _StageTimer(self, name, fraction)


class _StageTimer:
    """Times one stage and reports progress when it finishes."""

    def __init__(self, context: AnalysisContext, name: str, fraction: float) -> None:
        self._context = context
        self._name = name
        self._fraction = fraction
        self._started = 0.0

    def __enter__(self) -> _StageTimer:
        self._started = time.perf_counter()
        return self

    def __exit__(self, *exc_info: object) -> None:
        elapsed_ms = int((time.perf_counter() - self._started) * 1000)
        self._context.timings.append(StageTiming(stage=self._name, duration_ms=elapsed_ms))
        if self._context.progress is not None:
            self._context.progress(self._name, self._fraction)
