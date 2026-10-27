"""Which instrument the screenshot actually shows, checked against the one being analysed.

Every number in a report is verified against the OHLCV Laravel sends, but nothing checked that the
OHLCV and the screenshot were the same company. Type the wrong ticker in the upload form — or
leave a stale one in it — and the engine analysed one instrument's price history under another
one's chart, with no stage able to notice: the image was passed in and never opened. The report
that comes out is internally consistent, correctly computed, and about the wrong stock.

This stage opens it. The vision model reads the chart's title; the comparison against the declared
symbol happens here in plain Python, where it can be tested and explained.

## It is a gate, not an input

Nothing read here reaches the analysis. A 3B vision model's transcription is not evidence, and the
moment a level or a price it "read" could enter a report, the guarantee that every number is
computed would be gone. The only thing this stage can do is stop a run.

## Only a positive contradiction fails

A small model will sometimes fail to read a title at all, and a false mismatch blocks a legitimate
analysis — so silence is never treated as disagreement. A run fails only when the screenshot names
something we can recognise as *different*: a ticker that is in the exchange's own symbol list and
is not the declared one, or a company name whose distinctive words share nothing with the declared
company's. Unreadable, blank, or a name too generic to judge ("Hydropower Ltd") is a warning on the
report, not a refusal.

The model is never told which symbol is expected. Asked "is this PCBL?", a 3B model says yes.
"""

from __future__ import annotations

import base64
import difflib
import re
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path
from typing import Literal

import httpx
import structlog
from pydantic import BaseModel, ConfigDict, ValidationError

from ai_engine.models.ollama import ChatMessage, OllamaClient, OllamaError
from ai_engine.models.sync import run_blocking

log = structlog.get_logger(__name__)

PROMPT_DIR = Path(__file__).resolve().parent.parent / "prompts"
PROMPT_VERSION = "chart_identity.v1"

TEMPERATURE = 0.0
"""Transcription, not writing: the same picture should give the same answer every time."""

KEEP_ALIVE = "30s"
"""Unload the vision model quickly. On the 4 GB profile it shares the GPU with the analyst, which
runs minutes later in the same analysis; holding both resident spills layers to the CPU."""

MAX_IMAGE_BYTES = 8 * 1024 * 1024
"""Stored charts are re-encoded PNGs of a few hundred KB. Anything far larger is not one."""

SIMILAR_ENOUGH = 0.86
"""How close two words must be to count as the same one, to absorb a misread character."""


@dataclass(frozen=True, slots=True)
class VisionConnection:
    """How to reach the vision model, and where the images live."""

    base_url: str
    model: str
    storage_root: Path
    timeout_seconds: float = 180.0
    num_ctx: int | None = None
    transport: httpx.AsyncBaseTransport | None = None
    """Test seam: lets the suite drive the exchange without a GPU."""


class ChartIdentity(BaseModel):
    """What the model says is printed on the chart. Empty strings mean "not printed"."""

    model_config = ConfigDict(extra="ignore")

    is_price_chart: bool = True
    title: str = ""
    ticker: str = ""
    company_name: str = ""


@dataclass(frozen=True, slots=True)
class Reading:
    """What came back from the model, and whether it was reachable at all.

    The difference matters to the report: a title the model could not make out is a fact about the
    screenshot, while a model that is not installed means nothing was checked — and a user who
    believes their charts are being verified when they are not is back where this started.
    """

    identity: ChartIdentity | None = None
    unavailable: bool = False


Status = Literal["match", "mismatch", "unverified"]


@dataclass(frozen=True, slots=True)
class Verdict:
    """The comparison's outcome. `message` is written for the person reading the report."""

    status: Status
    message: str = ""
    read_label: str | None = None
    """What the chart appeared to be, when that could be made out."""

    @property
    def is_mismatch(self) -> bool:
        return self.status == "mismatch"


# --------------------------------------------------------------------------- reading the image


def read(connection: VisionConnection, image_path: str) -> Reading:
    """Transcribe the chart's title. Never raises: no failure here may fail an analysis.

    Ollama down, the model not pulled, a timeout — all come back as `unavailable`. A reply that is
    not the schema, or an image that cannot be opened, come back empty but reachable.
    """
    resolved = _resolve(connection.storage_root, image_path)
    if resolved is None:
        log.warning("identity.image_unreadable", image_path=image_path)
        return Reading()

    try:
        return Reading(run_blocking(_read_async(connection, resolved)))
    except OllamaError as error:
        log.warning("identity.unavailable", error=str(error), model=connection.model)
        return Reading(unavailable=True)


async def _read_async(connection: VisionConnection, image: bytes) -> ChartIdentity | None:
    client = OllamaClient(
        connection.base_url,
        timeout_seconds=connection.timeout_seconds,
        transport=connection.transport,
    )
    messages: list[ChatMessage] = [
        {"role": "system", "content": _prompt()},
        {
            "role": "user",
            "content": "Which instrument does this chart show?",
            "images": [base64.b64encode(image).decode("ascii")],
        },
    ]
    try:
        result = await client.chat(
            model=connection.model,
            messages=messages,
            response_format=ChartIdentity.model_json_schema(),
            temperature=TEMPERATURE,
            think=False,
            num_ctx=connection.num_ctx,
            keep_alive=KEEP_ALIVE,
        )
    finally:
        await client.aclose()

    try:
        return ChartIdentity.model_validate_json(result.content)
    except ValidationError as error:
        log.warning("identity.unparseable", problems=error.error_count(), model=connection.model)
        return None


def _resolve(storage_root: Path, image_path: str) -> bytes | None:
    """Read the image from inside the storage root. The path is the caller's, so it is checked."""
    given = Path(image_path)
    root = storage_root.resolve()
    candidate = (given if given.is_absolute() else root / given).resolve()

    if not candidate.is_relative_to(root) or not candidate.is_file():
        return None
    if candidate.stat().st_size > MAX_IMAGE_BYTES:
        return None

    try:
        return candidate.read_bytes()
    except OSError:
        return None


@lru_cache(maxsize=2)
def _prompt() -> str:
    return (PROMPT_DIR / f"{PROMPT_VERSION}.md").read_text(encoding="utf-8")


# --------------------------------------------------------------------------- the comparison

_WORD = re.compile(r"[A-Za-z0-9]+")
_TICKER = re.compile(r"^[A-Z][A-Z0-9]{1,14}$")

LEGAL_WORDS = frozenset(
    {
        "LTD",
        "LIMITED",
        "LTDS",
        "COMPANY",
        "CO",
        "CORP",
        "CORPORATION",
        "PLC",
        "PVT",
        "PRIVATE",
        "INC",
        "PUBLIC",
        "AND",
        "THE",
        "OF",
        "GROUP",
        "HOLDINGS",
        "HOLDING",
    }
)
"""Words that say nothing about which company this is."""

INDUSTRY_WORDS = frozenset(
    {
        "HYDROPOWER",
        "HYDRO",
        "POWER",
        "ENERGY",
        "ELECTRICITY",
        "BANK",
        "BANKING",
        "COMMERCIAL",
        "DEVELOPMENT",
        "FINANCE",
        "FINANCIAL",
        "INSURANCE",
        "LIFE",
        "NONLIFE",
        "GENERAL",
        "INVESTMENT",
        "CAPITAL",
        "MICROFINANCE",
        "LAGHUBITTA",
        "BITTIYA",
        "SANSTHA",
        "MUTUAL",
        "FUND",
        "MANUFACTURING",
        "INDUSTRIES",
        "INDUSTRY",
        "TRADING",
        "HOTEL",
        "HOTELS",
        "TELECOM",
        "CEMENT",
        "STEEL",
        "NEPAL",
        "NEPALESE",
    }
)
"""Common to half the exchange. Two names sharing only these are not the same company; a name made
only of these ("Hydropower Ltd") says nothing either way."""

CHART_NOISE = frozenset(
    {
        "NEPSE",
        "NSE",
        "BSE",
        "NASDAQ",
        "NYSE",
        "TRADINGVIEW",
        "CHUKUL",
        "COM",
        "WWW",
        "HTTPS",
        "SELL",
        "BUY",
        "VOLUME",
        "SMA",
        "EMA",
        "RSI",
        "MACD",
        "LOG",
        "AUTO",
        "ADJ",
        "OPEN",
        "HIGH",
        "LOW",
        "CLOSE",
        "UTC",
        "AM",
        "PM",
    }
)
"""Printed on every chart regardless of what it shows."""

_IGNORED = LEGAL_WORDS | INDUSTRY_WORDS | CHART_NOISE


def verify(
    identity: ChartIdentity | None,
    *,
    declared_symbol: str | None,
    declared_name: str | None,
    universe: list[str] | None = None,
) -> Verdict:
    """Compare what was read off the chart with the instrument the analysis was requested for."""
    if identity is None:
        return Verdict("unverified")

    known = {symbol.strip().upper() for symbol in (universe or []) if symbol.strip()}
    declared = (declared_symbol or "").strip().upper()
    read_ticker = _ticker(identity, known)
    read_label = read_ticker or (identity.company_name or identity.title or "").strip() or None

    if not identity.is_price_chart and read_label is None:
        return Verdict(
            "unverified",
            "The chart image could not be recognised as a price chart, so it was not possible to "
            "confirm which instrument it shows.",
        )

    if declared == "":
        # Nothing to contradict, but knowing what the picture looks like is worth saying: this is
        # the symbol-less Tier C run, and naming it is the fix.
        if read_ticker is not None and read_ticker in known:
            return Verdict(
                "unverified",
                f"The screenshot looks like {read_ticker}, but no symbol is attached to this chart, "
                "so no price history was used.",
                read_ticker,
            )
        return Verdict("unverified", read_label=read_label)

    if read_ticker is not None:
        if read_ticker == declared:
            return Verdict("match", read_label=read_ticker)
        if read_ticker in known:
            return Verdict(
                "mismatch",
                f"The screenshot is a chart of {read_ticker}, but the analysis was requested for {declared}.",
                read_ticker,
            )

    return _compare_names(identity, declared, declared_name, read_label)


def _compare_names(
    identity: ChartIdentity, declared: str, declared_name: str | None, read_label: str | None
) -> Verdict:
    """Fall back to the company name, which is what NEPSE charts actually print."""
    read_words = _distinctive(f"{identity.company_name} {identity.title}")
    declared_words = _distinctive(declared_name or "")

    if not read_words or not declared_words:
        return Verdict("unverified", read_label=read_label)

    if any(_same_word(left, right) for left in read_words for right in declared_words):
        return Verdict("match", read_label=read_label)

    printed = (identity.company_name or identity.title).strip()
    return Verdict(
        "mismatch",
        f"The screenshot is titled “{printed}”, which is not {declared}"
        + (f" ({declared_name})." if declared_name else "."),
        read_label,
    )


def _ticker(identity: ChartIdentity, known: set[str]) -> str | None:
    """The ticker the model read, or one printed in the title that the exchange recognises."""
    candidate = identity.ticker.strip().upper().rsplit(":", 1)[-1]
    if _TICKER.match(candidate):
        return candidate

    # A ticker in a title is printed in capitals ("NEPSE:NABIL · 1D"). Matching case-insensitively
    # would read the "Upper" of "Upper Tamakoshi Hydropower" as the ticker UPPER, which is right
    # for that company and wrong for every other one whose name starts with the same word.
    title_words: list[str] = _WORD.findall(identity.title)
    for word in title_words:
        if word.isupper() and word in known and word not in CHART_NOISE:
            return word
    return None


def _distinctive(text: str) -> list[str]:
    """The words in a company name that actually distinguish it from another company."""
    return [
        word
        for word in _WORD.findall(text.upper())
        if len(word) > 2 and not word.isdigit() and word not in _IGNORED
    ]


def _same_word(left: str, right: str) -> bool:
    """Equal, or close enough that a misread character is the likelier explanation."""
    if left == right:
        return True
    return difflib.SequenceMatcher(None, left, right).ratio() >= SIMILAR_ENOUGH


__all__ = [
    "ChartIdentity",
    "Reading",
    "Verdict",
    "VisionConnection",
    "read",
    "verify",
]
