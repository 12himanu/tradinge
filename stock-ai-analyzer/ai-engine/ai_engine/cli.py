"""Developer CLI for the deterministic pipeline (P4A-20).

    uv run python -m ai_engine.cli analyze --csv data/HDL.csv --symbol HDL --style swing \
        --account 500000

Reads the CSV format in docs/csv-format.md, runs the Tier A analysis and prints the Markdown
report. `--json PATH` also writes the full `AnalysisResult`.
"""

from __future__ import annotations

import argparse
import csv
import sys
from collections.abc import Sequence
from datetime import UTC, datetime
from pathlib import Path

from pydantic import ValidationError

from ai_engine.errors import ConfigurationError, EngineError
from ai_engine.pipeline.config import load_engine_config
from ai_engine.pipeline.orchestrator import analyse
from ai_engine.schemas.analysis import (
    AccountSettings,
    AnalysisRequest,
    Bar,
    FeeSchedule,
    Position,
)
from ai_engine.settings import PROJECT_ROOT

COLUMN_ALIASES: dict[str, tuple[str, ...]] = {
    "date": ("date", "time", "datetime", "timestamp", "tradingdate", "businessdate", "publisheddate"),
    "open": ("open", "openprice", "openingprice"),
    "high": ("high", "highprice", "maxprice"),
    "low": ("low", "lowprice", "minprice"),
    "close": ("close", "closeprice", "closingprice", "ltp", "lasttradedprice"),
    "volume": ("volume", "vol", "tradedquantity", "totaltradedquantity", "tradedshares", "qty"),
}

DATE_FORMATS = (
    "%Y-%m-%d",
    "%Y/%m/%d",
    "%d/%m/%Y",
    "%d-%m-%Y",
    "%d.%m.%Y",
    "%Y-%m-%d %H:%M:%S",
    "%Y-%m-%dT%H:%M:%S",
)


def read_csv(path: Path) -> list[Bar]:
    """Read OHLCV bars, skipping rows that fail validation and reporting them on stderr."""
    text = path.read_text(encoding="utf-8-sig")
    sample = text.splitlines()[0] if text else ""
    delimiter = max(",;\t|", key=sample.count)
    reader = csv.DictReader(text.splitlines(), delimiter=delimiter)
    if reader.fieldnames is None:
        raise EngineError("INVALID_CSV", f"{path} has no header row", status_code=422)

    mapping = _map_columns(reader.fieldnames)
    missing = [name for name in ("date", "open", "high", "low", "close") if name not in mapping]
    if missing:
        raise EngineError(
            "INVALID_CSV", f"{path} is missing required columns: {', '.join(missing)}", status_code=422
        )

    bars: list[Bar] = []
    skipped = 0
    for line, row in enumerate(reader, start=2):
        try:
            bars.append(
                Bar(
                    date=_parse_date(row[mapping["date"]]),
                    open=_number(row[mapping["open"]]),
                    high=_number(row[mapping["high"]]),
                    low=_number(row[mapping["low"]]),
                    close=_number(row[mapping["close"]]),
                    volume=(
                        _number(row[mapping["volume"]])
                        if "volume" in mapping and row.get(mapping["volume"])
                        else None
                    ),
                )
            )
        except (ValueError, ValidationError, KeyError, TypeError) as exc:
            skipped += 1
            print(f"  line {line} skipped: {exc}", file=sys.stderr)
    if skipped:
        print(f"  {skipped} row(s) skipped, {len(bars)} kept", file=sys.stderr)
    return sorted(bars, key=lambda bar: bar.date)


def _map_columns(fieldnames: Sequence[str]) -> dict[str, str]:
    normalized = {name: "".join(ch for ch in name.lower() if ch.isalnum()) for name in fieldnames}
    mapping: dict[str, str] = {}
    for canonical, aliases in COLUMN_ALIASES.items():
        for name, cleaned in normalized.items():
            if cleaned in aliases:
                mapping[canonical] = name
                break
    return mapping


def _number(raw: str) -> float:
    return float(raw.replace(",", "").replace('"', "").strip())


def _parse_date(raw: str) -> datetime:
    value = raw.strip().strip('"')
    if value.isdigit():
        seconds = int(value)
        if seconds > 10_000_000_000:  # milliseconds
            seconds //= 1000
        return datetime.fromtimestamp(seconds, tz=UTC)
    for fmt in DATE_FORMATS:
        try:
            return datetime.strptime(value, fmt).replace(tzinfo=UTC)
        except ValueError:
            continue
    raise ValueError(f"unrecognised date {value!r}")


def build_request(args: argparse.Namespace, bars: list[Bar]) -> AnalysisRequest:
    return AnalysisRequest(
        analysis_id=args.analysis_id,
        symbol=args.symbol,
        timeframe=args.timeframe,
        trading_style=args.style,
        account=AccountSettings(
            equity=args.account,
            risk_pct=args.risk_pct,
            max_position_pct=args.max_position_pct,
            lot_size=args.lot_size,
        ),
        fees=FeeSchedule(buy_pct=args.fee_buy_pct, sell_pct=args.fee_sell_pct),
        position=Position(
            status="holding" if args.holding_entry else "none",
            entry_price=args.holding_entry,
            quantity=args.holding_quantity,
            stop_price=args.holding_stop,
        ),
        bars=bars,
        user_thesis=args.thesis,
    )


def _force_utf8_output() -> None:
    """Windows consoles default to cp1252, which cannot print the report's ✓ and ≈."""
    for stream in (sys.stdout, sys.stderr):
        reconfigure = getattr(stream, "reconfigure", None)
        if reconfigure is not None:
            reconfigure(encoding="utf-8", errors="replace")


def main(argv: list[str] | None = None) -> int:
    _force_utf8_output()
    parser = argparse.ArgumentParser(prog="ai_engine.cli", description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)

    analyze = subparsers.add_parser("analyze", help="Run the deterministic analysis on a CSV")
    analyze.add_argument("--csv", type=Path, required=True)
    analyze.add_argument("--symbol", required=True)
    analyze.add_argument("--timeframe", default="1D", choices=["15m", "1h", "4h", "1D", "1W"])
    analyze.add_argument("--style", default="swing", choices=["intraday", "swing", "positional", "investing"])
    analyze.add_argument("--account", type=float, default=500_000.0, help="Account equity")
    analyze.add_argument("--risk-pct", dest="risk_pct", type=float, default=1.0)
    analyze.add_argument("--max-position-pct", dest="max_position_pct", type=float, default=25.0)
    analyze.add_argument("--lot-size", dest="lot_size", type=int, default=10)
    analyze.add_argument("--fee-buy-pct", dest="fee_buy_pct", type=float, default=0.4)
    analyze.add_argument("--fee-sell-pct", dest="fee_sell_pct", type=float, default=0.4)
    analyze.add_argument("--holding-entry", dest="holding_entry", type=float, default=None)
    analyze.add_argument("--holding-quantity", dest="holding_quantity", type=float, default=None)
    analyze.add_argument("--holding-stop", dest="holding_stop", type=float, default=None)
    analyze.add_argument("--thesis", default=None, help="Your own thesis, reviewed in Phase 4C")
    analyze.add_argument("--analysis-id", dest="analysis_id", default="cli-local")
    analyze.add_argument("--json", dest="json_path", type=Path, default=None)
    analyze.add_argument("--config-dir", dest="config_dir", type=Path, default=PROJECT_ROOT / "config")
    analyze.add_argument("--quiet", action="store_true", help="Only write the JSON result")

    args = parser.parse_args(argv)
    try:
        config = load_engine_config(args.config_dir)
        bars = read_csv(args.csv)
        if not bars:
            raise EngineError("INSUFFICIENT_DATA", f"No usable rows in {args.csv}", status_code=422)
        result = analyse(build_request(args, bars), config)
    except (EngineError, ConfigurationError) as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 1

    if args.json_path is not None:
        args.json_path.write_text(result.model_dump_json(indent=2), encoding="utf-8", newline="\n")
        print(f"wrote {args.json_path}", file=sys.stderr)
    if not args.quiet:
        print(result.report_markdown or "")
    total_ms = sum(timing.duration_ms for timing in result.timings)
    print(f"[{total_ms} ms · {len(result.evidence)} evidence items]", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
