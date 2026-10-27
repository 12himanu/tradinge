"""Benchmark Ollama models on this machine and recommend a model profile (P2-10, P2-11).

    py infra/scripts/benchmark_ollama.py                    # models of the active profile, newest 3 uploads
    py infra/scripts/benchmark_ollama.py --charts a.png b.png c.png
    py infra/scripts/benchmark_ollama.py --vision qwen2.5vl:3b gemma3:4b --text qwen3:4b llama3.2:3b
    py infra/scripts/benchmark_ollama.py --pin              # also pin the chosen models' digests in models.yaml

Needs Ollama running with the models pulled, and three real chart screenshots: by default the three
newest images under storage/charts (upload them on the Analyze page first).

For each model it measures:
- vision: cold load time, per-chart latency and output speed, and whether each reply was valid JSON
- text: load time right after a vision model was loaded (the model swap on a 4 GB GPU), prompt
  processing time and generation speed for an analyst-sized reply
- peak GPU memory while the model ran (nvidia-smi), and how much of the model Ollama put on the GPU

Writes infra/benchmark-report.md. Standard library only, so it runs without the engine's virtualenv.
"""

from __future__ import annotations

import argparse
import base64
import json
import os
import re
import shutil
import statistics
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
from collections.abc import Iterator
from contextlib import contextmanager
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
MODELS_YAML = ROOT / "ai-engine" / "config" / "models.yaml"
DEFAULT_REPORT = ROOT / "infra" / "benchmark-report.md"
CHARTS_DIR = ROOT / "storage" / "charts"

VISION_PROMPT = (
    "You are reading a stock price chart screenshot. Reply with JSON only, in this shape: "
    '{"symbol": string or null, "timeframe": string or null, '
    '"chart_type": "candlestick" | "line" | "bar" | "other", "trend": "up" | "down" | "sideways", '
    '"price_axis_labels": [the numbers printed on the price axis, top to bottom], '
    '"indicators": [names of the indicators you can see]}. '
    "Use null or an empty list for anything you cannot read. Do not guess numbers."
)

TEXT_PROMPT = (
    "Write a technical-analysis briefing of about 250 words for a swing trader. Facts: last close 552.1; "
    "EMA20 540.3 and rising; EMA50 522.8; RSI(14) 63.4; a resistance zone at 580-583.5 tested twice; "
    "support at 520; volume 1.4 times its 20-day average on the last up day. Cover the trend, momentum, "
    "the key levels, a bullish and a bearish scenario with their triggers, and what would invalidate the "
    "setup. Use only the numbers given."
)

# An analyst reply (~600 tokens) should take at most about 75 s.
MIN_TEXT_TOKENS_PER_SECOND = 8.0

PROFILE_LINE = re.compile(r"^  ([\w-]+):")
MODEL_LINE = re.compile(r'^(\s+)(vision|text):\s*\{\s*tag:\s*"([^"]+)",\s*digest:\s*(null|"[^"]*")\s*\}(.*)$')


class OllamaError(RuntimeError):
    pass


class Ollama:
    def __init__(self, host: str, timeout: float) -> None:
        self.host = host.rstrip("/")
        self.timeout = timeout

    def request(self, path: str, payload: dict[str, Any] | None = None) -> dict[str, Any]:
        data = None if payload is None else json.dumps(payload).encode()
        request = urllib.request.Request(
            self.host + path,
            data=data,
            headers={"Content-Type": "application/json"},
            method="GET" if payload is None else "POST",
        )
        try:
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                body = response.read()
        except urllib.error.HTTPError as error:
            detail = error.read().decode(errors="replace")[:300]
            raise OllamaError(f"{path}: HTTP {error.code}: {detail}") from error
        except (urllib.error.URLError, TimeoutError, ConnectionError) as error:
            raise OllamaError(f"cannot reach Ollama at {self.host} ({error})") from error
        result = json.loads(body or b"{}")
        return result if isinstance(result, dict) else {}

    def version(self) -> str:
        return str(self.request("/api/version").get("version", "unknown"))

    def installed(self) -> dict[str, str]:
        """Installed model name -> digest."""
        models = self.request("/api/tags").get("models", [])
        return {str(m["name"]): str(m.get("digest", "")) for m in models if isinstance(m, dict) and "name" in m}

    def loaded(self) -> list[dict[str, Any]]:
        return [m for m in self.request("/api/ps").get("models", []) if isinstance(m, dict)]

    def unload_all(self) -> None:
        for model in self.loaded():
            self.request("/api/generate", {"model": model.get("name"), "keep_alive": 0})

    def gpu_fraction(self, model: str) -> float | None:
        """Share of the loaded model in VRAM; below 1.0 means Ollama offloaded layers to the CPU."""
        for loaded in self.loaded():
            if loaded.get("name") == model and loaded.get("size"):
                return float(loaded.get("size_vram", 0)) / float(loaded["size"])
        return None

    def generate(
        self,
        model: str,
        prompt: str,
        *,
        images: list[str] | None = None,
        json_format: bool = False,
        think: bool | None = None,
        num_predict: int = 700,
    ) -> dict[str, Any]:
        payload: dict[str, Any] = {
            "model": model,
            "prompt": prompt,
            "stream": False,
            "keep_alive": "10m",
            "options": {"temperature": 0, "num_predict": num_predict},
        }
        if images:
            payload["images"] = images
        if json_format:
            payload["format"] = "json"
        if think is not None:
            payload["think"] = think
        try:
            return self.request("/api/generate", payload)
        except OllamaError as error:
            # Models without a thinking mode may reject the flag; retry without it.
            if think is not None and "think" in str(error).lower():
                del payload["think"]
                return self.request("/api/generate", payload)
            raise


class VramSampler:
    """Polls nvidia-smi for used GPU memory while a block runs."""

    def __init__(self, interval: float = 0.25) -> None:
        self.available = shutil.which("nvidia-smi") is not None
        self.interval = interval
        self.peak_mb: int | None = None

    @staticmethod
    def gpu() -> tuple[str, int] | None:
        """(name, total MB) of the first GPU."""
        if shutil.which("nvidia-smi") is None:
            return None
        output = subprocess.run(
            ["nvidia-smi", "--query-gpu=name,memory.total", "--format=csv,noheader,nounits"],
            capture_output=True, text=True, timeout=10, check=False,
        ).stdout.strip()
        if not output:
            return None
        name, total = output.splitlines()[0].rsplit(",", 1)
        return name.strip(), int(total.strip())

    def _used_mb(self) -> int | None:
        output = subprocess.run(
            ["nvidia-smi", "--query-gpu=memory.used", "--format=csv,noheader,nounits"],
            capture_output=True, text=True, timeout=5, check=False,
        ).stdout.strip()
        return int(output.splitlines()[0]) if output else None

    @contextmanager
    def measure(self) -> Iterator[None]:
        self.peak_mb = None
        if not self.available:
            yield
            return
        stop = threading.Event()

        def poll() -> None:
            while not stop.is_set():
                used = self._used_mb()
                if used is not None:
                    self.peak_mb = max(self.peak_mb or 0, used)
                stop.wait(self.interval)

        thread = threading.Thread(target=poll, daemon=True)
        thread.start()
        try:
            yield
        finally:
            stop.set()
            thread.join()


@dataclass
class VisionResult:
    model: str
    load_s: float = 0.0
    latencies_s: list[float] = field(default_factory=list)
    tokens_per_s: list[float] = field(default_factory=list)
    valid_json: int = 0
    charts: int = 0
    peak_vram_mb: int | None = None
    gpu_fraction: float | None = None
    error: str | None = None

    @property
    def median_latency_s(self) -> float:
        return statistics.median(self.latencies_s) if self.latencies_s else float("inf")


@dataclass
class TextResult:
    model: str
    swap_s: float = 0.0
    prompt_s: float = 0.0
    tokens_per_s: float = 0.0
    output_tokens: int = 0
    total_s: float = 0.0
    peak_vram_mb: int | None = None
    gpu_fraction: float | None = None
    error: str | None = None


def seconds(nanoseconds: object) -> float:
    return float(nanoseconds) / 1e9 if isinstance(nanoseconds, (int, float)) else 0.0


def output_speed(reply: dict[str, Any]) -> float:
    duration = seconds(reply.get("eval_duration"))
    return float(reply.get("eval_count", 0)) / duration if duration > 0 else 0.0


def is_json(text: object) -> bool:
    if not isinstance(text, str):
        return False
    try:
        return isinstance(json.loads(text), dict)
    except json.JSONDecodeError:
        return False


def bench_vision(ollama: Ollama, model: str, charts: list[Path], sampler: VramSampler) -> VisionResult:
    result = VisionResult(model=model, charts=len(charts))
    try:
        ollama.unload_all()
        with sampler.measure():
            for index, chart in enumerate(charts):
                image = base64.b64encode(chart.read_bytes()).decode()
                reply = ollama.generate(model, VISION_PROMPT, images=[image], json_format=True, num_predict=400)
                load = seconds(reply.get("load_duration"))
                if index == 0:
                    result.load_s = load
                result.latencies_s.append(seconds(reply.get("total_duration")) - load)
                result.tokens_per_s.append(output_speed(reply))
                result.valid_json += int(is_json(reply.get("response")))
                print(f"    {chart.name}: {result.latencies_s[-1]:.1f} s, json={is_json(reply.get('response'))}")
            result.gpu_fraction = ollama.gpu_fraction(model)
        result.peak_vram_mb = sampler.peak_mb
    except OllamaError as error:
        result.error = str(error)
    return result


def bench_text(ollama: Ollama, model: str, after_vision: str | None, sampler: VramSampler) -> TextResult:
    result = TextResult(model=model)
    try:
        ollama.unload_all()
        if after_vision:
            ollama.generate(after_vision, "Reply with OK.", num_predict=5)
        with sampler.measure():
            warm = ollama.generate(model, "Reply with OK.", think=False, num_predict=5)
            result.swap_s = seconds(warm.get("load_duration"))
            runs = [ollama.generate(model, TEXT_PROMPT, think=False) for _ in range(2)]
            result.gpu_fraction = ollama.gpu_fraction(model)
        result.peak_vram_mb = sampler.peak_mb
        result.prompt_s = statistics.median(seconds(r.get("prompt_eval_duration")) for r in runs)
        result.tokens_per_s = statistics.median(output_speed(r) for r in runs)
        result.output_tokens = int(statistics.median(int(r.get("eval_count", 0)) for r in runs))
        result.total_s = statistics.median(seconds(r.get("total_duration")) for r in runs)
        print(f"    {result.tokens_per_s:.1f} tokens/s, swap {result.swap_s:.1f} s")
    except OllamaError as error:
        result.error = str(error)
    return result


def profile_models(profile: str) -> dict[str, str]:
    """role -> tag for one profile of models.yaml."""
    current = None
    models: dict[str, str] = {}
    for line in MODELS_YAML.read_text(encoding="utf-8").splitlines():
        if match := PROFILE_LINE.match(line):
            current = match.group(1)
        elif (match := MODEL_LINE.match(line)) and current == profile:
            models[match.group(2)] = match.group(3)
    if not models:
        sys.exit(f"Profile '{profile}' not found in {MODELS_YAML.relative_to(ROOT)}.")
    return models


def pin_profile(profile: str, choices: dict[str, tuple[str, str]]) -> None:
    """Writes tag and digest for each role of `profile` into models.yaml, keeping everything else."""
    lines = MODELS_YAML.read_text(encoding="utf-8").splitlines()
    current = None
    for index, line in enumerate(lines):
        if match := PROFILE_LINE.match(line):
            current = match.group(1)
            continue
        match = MODEL_LINE.match(line)
        if match and current == profile and match.group(2) in choices:
            indent, role, rest = match.group(1), match.group(2), match.group(5)
            tag, digest = choices[role]
            lines[index] = f'{indent}{role}: {{ tag: "{tag}", digest: "sha256:{digest}" }}{rest}'
    MODELS_YAML.write_text("\n".join(lines) + "\n", encoding="utf-8", newline="\n")


def default_charts(count: int = 3) -> list[Path]:
    images = [p for p in CHARTS_DIR.rglob("*") if p.suffix.lower() in {".png", ".jpg", ".jpeg", ".webp"}]
    return sorted(images, key=lambda p: p.stat().st_mtime, reverse=True)[:count]


def env_value(key: str) -> str | None:
    env = ROOT / ".env"
    if env.exists():
        for line in env.read_text(encoding="utf-8").splitlines():
            if line.startswith(f"{key}="):
                return line.split("=", 1)[1].strip() or None
    return None


def choose(vision: list[VisionResult], text: list[TextResult]) -> tuple[VisionResult | None, TextResult | None, list[str]]:
    notes: list[str] = []

    usable_vision = [r for r in vision if r.error is None and r.charts]
    best_vision = min(
        usable_vision, key=lambda r: (-r.valid_json / r.charts, r.median_latency_s), default=None
    )

    usable_text = [r for r in text if r.error is None]
    fast_enough = [r for r in usable_text if r.tokens_per_s >= MIN_TEXT_TOKENS_PER_SECOND]
    best_text = max(fast_enough or usable_text, key=lambda r: r.tokens_per_s, default=None)
    if best_text and not fast_enough:
        notes.append(
            f"No text model reached {MIN_TEXT_TOKENS_PER_SECOND:.0f} tokens/s; narratives will be slow. "
            "Consider a smaller model or the template-only narrative."
        )

    for chosen in (best_vision, best_text):
        if chosen and chosen.gpu_fraction is not None and chosen.gpu_fraction < 0.99:
            notes.append(
                f"`{chosen.model}` ran {chosen.gpu_fraction:.0%} on the GPU; the rest was offloaded to the "
                "CPU, which slows it down."
            )
    if best_vision and best_vision.valid_json < best_vision.charts:
        notes.append(
            f"`{best_vision.model}` returned invalid JSON for {best_vision.charts - best_vision.valid_json} of "
            f"{best_vision.charts} charts. The engine's schema-constrained output and repair step must cover this."
        )
    return best_vision, best_text, notes


def fmt(value: float | int | None, pattern: str = "{:.1f}") -> str:
    return "–" if value is None else pattern.format(value)


def write_report(
    path: Path,
    *,
    profile: str,
    ollama_version: str,
    gpu: tuple[str, int] | None,
    charts: list[Path],
    installed: dict[str, str],
    vision: list[VisionResult],
    text: list[TextResult],
    best_vision: VisionResult | None,
    best_text: TextResult | None,
    notes: list[str],
    pinned: bool,
) -> None:
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    gpu_line = f"{gpu[0]}, {gpu[1]:,} MB" if gpu else "not detected (nvidia-smi unavailable)"
    lines = [
        "# Ollama benchmark",
        "",
        f"Generated {now} by `infra/scripts/benchmark_ollama.py`. Profile under test: `{profile}`.",
        "",
        f"- GPU: {gpu_line}",
        f"- Ollama: {ollama_version}",
        f"- Charts: {', '.join(p.name for p in charts)}",
        "",
        "## Vision models",
        "",
        "| Model | Cold load (s) | Median latency per chart (s) | Output (tokens/s) | Valid JSON | Peak VRAM (MB) | On GPU | Error |",
        "|-------|---------------|------------------------------|-------------------|------------|----------------|--------|-------|",
    ]
    for r in vision:
        speed = statistics.median(r.tokens_per_s) if r.tokens_per_s else None
        lines.append(
            f"| `{r.model}` | {fmt(r.load_s)} | {fmt(r.median_latency_s if r.latencies_s else None)} | {fmt(speed)} "
            f"| {r.valid_json}/{r.charts} | {fmt(r.peak_vram_mb, '{:,}')} | {fmt(r.gpu_fraction, '{:.0%}')} | {r.error or ''} |"
        )
    lines += [
        "",
        "## Text models",
        "",
        "| Model | Load after vision model (s) | Prompt processing (s) | Output (tokens/s) | Reply tokens | Total per reply (s) | Peak VRAM (MB) | On GPU | Error |",
        "|-------|-----------------------------|-----------------------|-------------------|--------------|---------------------|----------------|--------|-------|",
    ]
    for r in text:
        failed = r.error is not None
        lines.append(
            f"| `{r.model}` | {fmt(None if failed else r.swap_s)} | {fmt(None if failed else r.prompt_s)} "
            f"| {fmt(None if failed else r.tokens_per_s)} | {fmt(None if failed else r.output_tokens, '{}')} "
            f"| {fmt(None if failed else r.total_s)} | {fmt(r.peak_vram_mb, '{:,}')} | {fmt(r.gpu_fraction, '{:.0%}')} | {r.error or ''} |"
        )

    lines += ["", "## Recommendation", ""]
    if best_vision and best_text:
        lines += [
            f"Vision `{best_vision.model}` and text `{best_text.model}`. "
            "Vision is chosen by valid-JSON rate, then latency; text by generation speed "
            f"(at least {MIN_TEXT_TOKENS_PER_SECOND:.0f} tokens/s when possible).",
            "",
            "```yaml",
            f"  {profile}:",
            f'    vision: {{ tag: "{best_vision.model}", digest: "sha256:{installed.get(best_vision.model, "")}" }}',
            f'    text:   {{ tag: "{best_text.model}", digest: "sha256:{installed.get(best_text.model, "")}" }}',
            "```",
            "",
            "Pinned in `ai-engine/config/models.yaml`." if pinned else "Run again with `--pin` to write these digests into `ai-engine/config/models.yaml`.",
        ]
    else:
        lines.append("No recommendation: at least one vision and one text model must complete the benchmark.")
    if notes:
        lines += ["", "## Notes", ""] + [f"- {note}" for note in notes]
    lines += [
        "",
        "Latencies exclude model loading. Output speed is Ollama's `eval_count / eval_duration`. "
        "Text models run with thinking disabled. On a 4 GB GPU only one model fits at a time, so every "
        "analysis pays the vision-to-text swap once.",
        "",
    ]
    path.write_text("\n".join(lines), encoding="utf-8", newline="\n")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--host", default="http://localhost:11434", help="Ollama URL (default: %(default)s)")
    parser.add_argument("--profile", default=env_value("MODEL_PROFILE") or "gpu_4gb", help="profile in models.yaml")
    parser.add_argument("--vision", nargs="+", help="vision models to compare (default: the profile's)")
    parser.add_argument("--text", nargs="+", help="text models to compare (default: the profile's)")
    parser.add_argument("--charts", nargs="+", type=Path, help="chart screenshots (default: newest 3 uploads)")
    parser.add_argument("--report", type=Path, default=DEFAULT_REPORT, help="report path (default: %(default)s)")
    parser.add_argument("--timeout", type=float, default=900, help="seconds per Ollama request")
    parser.add_argument("--pin", action="store_true", help="write the recommended tags and digests into models.yaml")
    args = parser.parse_args()

    profile = profile_models(args.profile)
    vision_models: list[str] = args.vision or [profile["vision"]]
    text_models: list[str] = args.text or [profile["text"]]

    charts: list[Path] = args.charts or default_charts()
    if len(charts) < 3:
        sys.exit(
            f"Need 3 chart screenshots, found {len(charts)} under {CHARTS_DIR.relative_to(ROOT)}. "
            "Upload charts on the Analyze page, or pass --charts a.png b.png c.png."
        )
    missing_files = [str(p) for p in charts if not p.is_file()]
    if missing_files:
        sys.exit(f"Chart file(s) not found: {', '.join(missing_files)}")

    ollama = Ollama(args.host, args.timeout)
    try:
        version = ollama.version()
        installed = ollama.installed()
    except OllamaError as error:
        sys.exit(f"{error}. Start Ollama (Start menu, or `ollama serve`) and try again.")

    absent = [m for m in vision_models + text_models if m not in installed]
    if absent:
        sys.exit("Not installed: " + ", ".join(absent) + "\nPull them first: " + "  ".join(f"ollama pull {m}" for m in absent))

    sampler = VramSampler()
    gpu = VramSampler.gpu()
    print(f"Ollama {version} · GPU: {gpu[0] if gpu else 'not detected'} · profile {args.profile}")

    vision_results = []
    for model in vision_models:
        print(f"  vision {model}")
        vision_results.append(bench_vision(ollama, model, charts, sampler))

    reference_vision = next((r.model for r in vision_results if r.error is None), None)
    text_results = []
    for model in text_models:
        print(f"  text {model}")
        text_results.append(bench_text(ollama, model, reference_vision, sampler))
    ollama.unload_all()

    best_vision, best_text, notes = choose(vision_results, text_results)
    pinned = False
    if args.pin and best_vision and best_text:
        pin_profile(args.profile, {
            "vision": (best_vision.model, installed[best_vision.model]),
            "text": (best_text.model, installed[best_text.model]),
        })
        pinned = True

    write_report(
        args.report, profile=args.profile, ollama_version=version, gpu=gpu, charts=charts, installed=installed,
        vision=vision_results, text=text_results, best_vision=best_vision, best_text=best_text, notes=notes,
        pinned=pinned,
    )
    print(f"Report: {args.report.relative_to(ROOT) if args.report.is_relative_to(ROOT) else args.report}")
    if pinned:
        print(f"Pinned {best_vision.model if best_vision else ''} and {best_text.model if best_text else ''} "
              f"in {MODELS_YAML.relative_to(ROOT)}")
    if os.environ.get("CI") is None and (best_vision is None or best_text is None):
        sys.exit(1)


if __name__ == "__main__":
    main()
