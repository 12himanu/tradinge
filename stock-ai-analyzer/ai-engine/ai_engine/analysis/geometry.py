"""Line and curve fitting used by the trendline-based pattern detectors."""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np

from ai_engine.analysis.series import FloatArray


@dataclass(frozen=True, slots=True)
class LineFit:
    slope: float
    intercept: float
    r2: float
    max_residual: float
    rms_residual: float

    def at(self, x: float) -> float:
        return self.slope * x + self.intercept

    def span(self, start: float, end: float) -> float:
        """Total rise over the window, used to judge whether a boundary is flat."""
        return self.at(end) - self.at(start)


def fit_line(xs: list[int] | FloatArray, ys: list[float] | FloatArray) -> LineFit | None:
    """Least-squares line through the points; None when there are fewer than two."""
    x = np.asarray(xs, dtype=np.float64)
    y = np.asarray(ys, dtype=np.float64)
    if x.size < 2 or np.ptp(x) == 0:
        return None
    slope, intercept = np.polyfit(x, y, 1)
    predicted = slope * x + intercept
    residuals = y - predicted
    total = float(np.sum((y - np.mean(y)) ** 2))
    r2 = 1.0 - float(np.sum(residuals**2)) / total if total > 0 else 1.0
    return LineFit(
        slope=float(slope),
        intercept=float(intercept),
        r2=float(r2),
        max_residual=float(np.max(np.abs(residuals))),
        rms_residual=float(np.sqrt(np.mean(residuals**2))),
    )


@dataclass(frozen=True, slots=True)
class QuadraticFit:
    a: float
    b: float
    c: float
    r2: float

    def at(self, x: float) -> float:
        return self.a * x * x + self.b * x + self.c

    @property
    def vertex_x(self) -> float | None:
        return None if self.a == 0 else -self.b / (2 * self.a)


def fit_quadratic(xs: FloatArray, ys: FloatArray) -> QuadraticFit | None:
    """Parabola fit, used to check that a cup base is rounded rather than a V."""
    if xs.size < 3:
        return None
    a, b, c = np.polyfit(xs, ys, 2)
    predicted = a * xs**2 + b * xs + c
    total = float(np.sum((ys - np.mean(ys)) ** 2))
    r2 = 1.0 - float(np.sum((ys - predicted) ** 2)) / total if total > 0 else 1.0
    return QuadraticFit(a=float(a), b=float(b), c=float(c), r2=float(r2))


def clamp(value: float, low: float = 0.0, high: float = 1.0) -> float:
    return max(low, min(high, value))


def fit_quality(fit: LineFit, atr_value: float, tolerance_atr: float) -> float:
    """1.0 for a perfect fit, falling to 0 as the RMS residual reaches the tolerance."""
    if atr_value <= 0 or tolerance_atr <= 0:
        return 0.0
    return clamp(1.0 - fit.rms_residual / (tolerance_atr * atr_value))
