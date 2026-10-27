"""The developer CLI: CSV reading and the analyze command (P4A-20)."""

from __future__ import annotations

import json
from datetime import UTC, datetime
from pathlib import Path

import pytest

from ai_engine import cli
from tests.fixtures import synthetic as syn

HEADER = "date,open,high,low,close,volume\n"


def write_series(path: Path, bars: int = 120, *, header: str = HEADER, delimiter: str = ",") -> Path:
    series = syn.ascending_triangle()
    lines = [header.replace(",", delimiter)]
    for index in range(min(bars, len(series))):
        lines.append(
            delimiter.join(
                [
                    series.dates[index].strftime("%Y-%m-%d"),
                    f"{series.open[index]:.2f}",
                    f"{series.high[index]:.2f}",
                    f"{series.low[index]:.2f}",
                    f"{series.close[index]:.2f}",
                    str(int(series.volume[index])),  # type: ignore[index]
                ]
            )
        )
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return path


def test_reads_the_documented_csv_shape(tmp_path: Path) -> None:
    bars = cli.read_csv(write_series(tmp_path / "syn.csv"))
    assert len(bars) == 113
    assert bars[0].date < bars[-1].date
    assert bars[0].volume is not None


def test_reads_tradingview_headers_and_semicolons(tmp_path: Path) -> None:
    path = write_series(tmp_path / "tv.csv", header="time;open;high;low;close;Volume\n", delimiter=";")
    assert len(cli.read_csv(path)) == 113


def test_thousands_separators_and_a_bom_are_tolerated(tmp_path: Path) -> None:
    path = tmp_path / "messy.csv"
    path.write_text(
        '﻿Date,Open Price,High,Low,Close Price\n2026-01-01,"1,050.00","1,060.00","1,040.00","1,055.00"\n',
        encoding="utf-8",
    )
    bars = cli.read_csv(path)
    assert bars[0].close == pytest.approx(1055.0)


def test_bad_rows_are_skipped_not_fatal(tmp_path: Path, capsys: pytest.CaptureFixture[str]) -> None:
    path = tmp_path / "broken.csv"
    path.write_text(
        HEADER
        + "2026-01-01,100,110,90,105,1000\n"
        + "2026-01-02,100,80,90,105,1000\n"  # high below low
        + "not-a-date,100,110,90,105,1000\n"
        + "2026-01-05,101,111,91,106,1200\n",
        encoding="utf-8",
    )
    bars = cli.read_csv(path)
    assert len(bars) == 2
    assert "skipped" in capsys.readouterr().err


def test_missing_columns_are_reported(tmp_path: Path) -> None:
    path = tmp_path / "thin.csv"
    path.write_text("date,close\n2026-01-01,100\n", encoding="utf-8")
    with pytest.raises(Exception, match="missing required columns"):
        cli.read_csv(path)


def test_unix_timestamps_parse() -> None:
    assert cli._parse_date("1788652800") == datetime.fromtimestamp(1788652800, tz=UTC)
    assert cli._parse_date("1788652800000").year == cli._parse_date("1788652800").year


def test_analyze_prints_a_report_and_writes_json(tmp_path: Path, capsys: pytest.CaptureFixture[str]) -> None:
    csv_path = write_series(tmp_path / "syn.csv")
    json_path = tmp_path / "result.json"
    exit_code = cli.main(
        [
            "analyze",
            "--csv",
            str(csv_path),
            "--symbol",
            "SYN",
            "--style",
            "swing",
            "--account",
            "500000",
            "--json",
            str(json_path),
        ]
    )
    assert exit_code == 0
    report = capsys.readouterr().out
    assert "# SYN · 1D · Swing" in report
    assert "Decision:" in report

    payload = json.loads(json_path.read_text(encoding="utf-8"))
    assert payload["tier"] == "A"
    assert payload["decision"]["action"]
    assert payload["evidence"]


def test_analyze_fails_cleanly_on_an_empty_file(tmp_path: Path, capsys: pytest.CaptureFixture[str]) -> None:
    path = tmp_path / "empty.csv"
    path.write_text(HEADER, encoding="utf-8")
    assert cli.main(["analyze", "--csv", str(path), "--symbol", "X"]) == 1
    assert "error:" in capsys.readouterr().err
