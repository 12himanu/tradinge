"""Array view over a `PriceSeries`.

The analysis functions are pure NumPy: they never see Pydantic models, I/O or configuration
beyond plain numbers, which keeps them cheap to test against synthetic fixtures.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime

import numpy as np
import numpy.typing as npt

from ai_engine.schemas.analysis import DataTier, PriceSeries, Timeframe

FloatArray = npt.NDArray[np.float64]


@dataclass(frozen=True, slots=True)
class Series:
    """OHLCV as parallel float arrays, oldest bar first."""

    symbol: str
    timeframe: Timeframe
    tier: DataTier
    dates: list[datetime]
    open: FloatArray
    high: FloatArray
    low: FloatArray
    close: FloatArray
    volume: FloatArray | None

    def __len__(self) -> int:
        return int(self.close.size)

    @property
    def has_volume(self) -> bool:
        return self.volume is not None and bool(np.any(self.volume > 0))

    @property
    def last_close(self) -> float:
        return float(self.close[-1])

    @property
    def as_of(self) -> datetime | None:
        return self.dates[-1] if self.dates else None

    def date_at(self, index: int) -> datetime:
        return self.dates[index]

    def slice(self, start: int) -> Series:
        """The last bars from `start` onward; used to honour a style's lookback window."""
        start = max(0, min(start, len(self)))
        return Series(
            symbol=self.symbol,
            timeframe=self.timeframe,
            tier=self.tier,
            dates=self.dates[start:],
            open=self.open[start:],
            high=self.high[start:],
            low=self.low[start:],
            close=self.close[start:],
            volume=None if self.volume is None else self.volume[start:],
        )

    @classmethod
    def from_price_series(cls, series: PriceSeries) -> Series:
        bars = series.bars
        volumes = [bar.volume for bar in bars]
        return cls(
            symbol=series.symbol,
            timeframe=series.timeframe,
            tier=series.tier,
            dates=[bar.date for bar in bars],
            open=np.array([bar.open for bar in bars], dtype=np.float64),
            high=np.array([bar.high for bar in bars], dtype=np.float64),
            low=np.array([bar.low for bar in bars], dtype=np.float64),
            close=np.array([bar.close for bar in bars], dtype=np.float64),
            volume=(
                None if any(volume is None for volume in volumes) else np.array(volumes, dtype=np.float64)
            ),
        )
