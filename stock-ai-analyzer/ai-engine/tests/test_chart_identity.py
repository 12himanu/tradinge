"""The chart-identity gate: does the screenshot show the instrument being analysed?

The case these tests exist for: a screenshot of Upper Tamakoshi analysed as PCBL. Every number in
that report was computed correctly from PCBL's price history, which is what made it impossible to
spot from the inside.
"""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

import httpx
import pytest

from ai_engine.agents.chart_identity_agent import (
    ChartIdentity,
    VisionConnection,
    read,
    verify,
)
from ai_engine.errors import EngineError
from ai_engine.pipeline.context import AnalysisContext
from ai_engine.pipeline.evidence import EvidenceStore
from ai_engine.pipeline.identity import check
from ai_engine.schemas.analysis import AccountSettings, AnalysisRequest

NEPSE = ["NABIL", "PCBL", "UPPER", "SSHL", "HDL", "NTC"]

UPPER = ChartIdentity(
    is_price_chart=True,
    title="Upper Tamakoshi Hydropower Ltd (chukul.com) · 1D · NEPSE",
    ticker="",
    company_name="Upper Tamakoshi Hydropower Ltd",
)


# --------------------------------------------------------------------------- the comparison


def test_company_name_on_the_chart_contradicts_the_declared_symbol() -> None:
    verdict = verify(
        UPPER, declared_symbol="PCBL", declared_name="Prime Commercial Bank Limited", universe=NEPSE
    )

    assert verdict.status == "mismatch"
    assert "Upper Tamakoshi" in verdict.message
    assert "PCBL" in verdict.message


def test_company_name_on_the_chart_confirms_the_declared_symbol() -> None:
    verdict = verify(
        UPPER,
        declared_symbol="UPPER",
        declared_name="Upper Tamakoshi Hydropower Limited",
        universe=NEPSE,
    )

    assert verdict.status == "match"


def test_a_misread_character_in_the_company_name_is_not_a_mismatch() -> None:
    """A 3B model transcribing "Tamakosi" must not block a legitimate analysis."""
    identity = ChartIdentity(title="Upper Tamakosi Hydropower Ltd · 1D · NEPSE")

    verdict = verify(
        identity, declared_symbol="UPPER", declared_name="Upper Tamakoshi Hydropower Limited", universe=NEPSE
    )

    assert verdict.status == "match"


def test_a_ticker_from_the_exchanges_own_list_settles_it() -> None:
    identity = ChartIdentity(ticker="NEPSE:NABIL", title="NEPSE:NABIL · 1D")

    assert verify(identity, declared_symbol="NABIL", declared_name=None, universe=NEPSE).status == "match"

    verdict = verify(identity, declared_symbol="PCBL", declared_name=None, universe=NEPSE)
    assert verdict.status == "mismatch"
    assert verdict.read_label == "NABIL"


def test_a_ticker_the_exchange_does_not_know_is_not_evidence_of_anything() -> None:
    """An unrecognised read is far likelier to be a misread than a different instrument."""
    identity = ChartIdentity(ticker="UPPFR", title="UPPFR · 1D")
    verdict = verify(identity, declared_symbol="UPPER", declared_name=None, universe=NEPSE)

    assert verdict.status == "unverified"


def test_two_companies_sharing_only_an_industry_word_still_mismatch() -> None:
    verdict = verify(
        ChartIdentity(company_name="Chilime Hydropower Company Ltd"),
        declared_symbol="UPPER",
        declared_name="Upper Tamakoshi Hydropower Limited",
        universe=NEPSE,
    )

    assert verdict.status == "mismatch"


def test_a_name_made_only_of_industry_words_decides_nothing() -> None:
    verdict = verify(
        ChartIdentity(company_name="Hydropower Ltd"),
        declared_symbol="UPPER",
        declared_name="Upper Tamakoshi Hydropower Limited",
        universe=NEPSE,
    )

    assert verdict.status == "unverified"


def test_an_unreadable_chart_never_fails_the_run() -> None:
    blank = ChartIdentity(is_price_chart=False, title="", ticker="", company_name="")
    declared: dict[str, Any] = {
        "declared_symbol": "PCBL",
        "declared_name": "Prime Commercial Bank",
        "universe": NEPSE,
    }

    assert verify(None, **declared).status == "unverified"
    assert verify(blank, **declared).status == "unverified"


def test_without_a_declared_symbol_the_read_becomes_a_hint() -> None:
    verdict = verify(ChartIdentity(ticker="UPPER"), declared_symbol=None, declared_name=None, universe=NEPSE)

    assert verdict.status == "unverified"
    assert "UPPER" in verdict.message


def test_the_vendor_watermark_is_not_mistaken_for_the_company() -> None:
    """The vendor domain and the exchange are printed on every chart from that site."""
    verdict = verify(
        ChartIdentity(title="NEPSE · chukul.com · 1D"),
        declared_symbol="PCBL",
        declared_name="Prime Commercial Bank Limited",
        universe=NEPSE,
    )

    assert verdict.status == "unverified"


# --------------------------------------------------------------------------- reading the image


def _png(tmp_path: Path) -> str:
    """A file that is not a real PNG, which is fine: only the vision model would care."""
    charts = tmp_path / "charts"
    charts.mkdir(exist_ok=True)
    (charts / "chart.png").write_bytes(b"\x89PNG\r\n\x1a\n" + b"0" * 64)
    return "charts/chart.png"


def _vision(tmp_path: Path, handler: Any) -> VisionConnection:
    return VisionConnection(
        base_url="http://ollama.test",
        model="qwen2.5vl:3b",
        storage_root=tmp_path,
        transport=httpx.MockTransport(handler),
    )


def test_read_sends_the_image_and_parses_the_reply(tmp_path: Path) -> None:
    seen: dict[str, Any] = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen.update(json.loads(request.content))
        return httpx.Response(
            200,
            json={
                "model": "qwen2.5vl:3b",
                "message": {"content": UPPER.model_dump_json()},
            },
        )

    reading = read(_vision(tmp_path, handler), _png(tmp_path))

    assert reading.identity is not None
    assert reading.identity.company_name == "Upper Tamakoshi Hydropower Ltd"
    assert seen["messages"][1]["images"], "the image never reached the model"
    # The model is never told which symbol is expected: asked "is this PCBL?", a 3B model agrees.
    assert "PCBL" not in json.dumps(seen)


def test_a_model_that_is_not_installed_reads_as_unavailable(tmp_path: Path) -> None:
    """`ollama pull` not run yet: nothing was checked, and the report has to say so."""

    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(404, json={"error": "model 'qwen2.5vl:3b' not found"})

    reading = read(_vision(tmp_path, handler), _png(tmp_path))

    assert reading.unavailable is True
    assert reading.identity is None


def test_a_reply_that_is_not_the_schema_reads_as_nothing(tmp_path: Path) -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={"message": {"content": "not json"}})

    reading = read(_vision(tmp_path, handler), _png(tmp_path))

    assert reading.identity is None
    assert reading.unavailable is False


def test_an_image_outside_the_storage_root_is_not_read(tmp_path: Path) -> None:
    def handler(request: httpx.Request) -> httpx.Response:  # pragma: no cover - must not be called
        raise AssertionError("the model was called for a path outside the storage root")

    assert read(_vision(tmp_path, handler), "../../etc/passwd").identity is None
    assert read(_vision(tmp_path, handler), "charts/missing.png").identity is None


# --------------------------------------------------------------------------- the pipeline stage


def _context(tmp_path: Path, **overrides: Any) -> AnalysisContext:
    values: dict[str, Any] = {
        "analysis_id": "01TEST",
        "symbol": "PCBL",
        "company_name": "Prime Commercial Bank Limited",
        "symbol_universe": NEPSE,
        "image_path": "charts/chart.png",
        "account": AccountSettings(equity=100_000),
    }
    values.update(overrides)
    request = AnalysisRequest(**values)
    return AnalysisContext(
        request=request,
        config=None,  # type: ignore[arg-type]  # unused by this stage
        evidence=EvidenceStore(tier="A", as_of=None),
        tier="A",
        data_quality=1.0,
    )


def _replies(identity: ChartIdentity) -> Any:
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={"message": {"content": identity.model_dump_json()}})

    return handler


def test_the_wrong_instrument_stops_the_run(tmp_path: Path) -> None:
    _png(tmp_path)
    context = _context(tmp_path)

    with pytest.raises(EngineError) as raised:
        check(context, _vision(tmp_path, _replies(UPPER)))

    assert raised.value.code == "SYMBOL_MISMATCH"
    assert raised.value.status_code == 422
    assert raised.value.details["declared_symbol"] == "PCBL"


def test_the_right_instrument_passes_without_a_warning(tmp_path: Path) -> None:
    _png(tmp_path)
    context = _context(tmp_path, symbol="UPPER", company_name="Upper Tamakoshi Hydropower Limited")

    check(context, _vision(tmp_path, _replies(UPPER)))

    assert context.warnings == []


def test_a_vision_model_that_cannot_be_reached_warns_that_nothing_was_checked(tmp_path: Path) -> None:
    _png(tmp_path)
    context = _context(tmp_path)

    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(404, json={"error": "model not found"})

    check(context, _vision(tmp_path, handler))

    assert len(context.warnings) == 1
    assert "not checked" in context.warnings[0]


def test_an_unreadable_title_warns_at_most(tmp_path: Path) -> None:
    _png(tmp_path)
    context = _context(tmp_path)

    check(context, _vision(tmp_path, _replies(ChartIdentity(is_price_chart=False))))

    assert len(context.warnings) == 1


def test_a_confirmed_symbol_is_not_checked_again_but_is_declared(tmp_path: Path) -> None:
    """The user has vouched for this pairing; the report still says the check was waived."""
    _png(tmp_path)
    context = _context(tmp_path, skip_symbol_check=True)

    def handler(request: httpx.Request) -> httpx.Response:  # pragma: no cover - must not be called
        raise AssertionError("the model was called for a chart the user confirmed")

    check(context, _vision(tmp_path, handler))

    assert len(context.warnings) == 1
    assert "PCBL" in context.warnings[0]


def test_no_connection_and_no_image_skip_the_check(tmp_path: Path) -> None:
    context = _context(tmp_path, image_path=None)

    check(context, None)
    check(context, _vision(tmp_path, _replies(UPPER)))

    assert context.warnings == []
