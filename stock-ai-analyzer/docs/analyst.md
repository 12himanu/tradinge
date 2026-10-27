# The analyst LLM

Phase 4C. The deterministic pipeline decides everything — bias, score, levels, scenarios, plans,
the decision. The analyst only explains it. That ordering is the whole design: **a model that
cannot reach a number cannot get one wrong.**

`narrative_source` on every analysis says which voice you are reading: `llm` when a draft passed
every guardrail, `template` when it did not or no model ran.

## The model writes prose, never numbers

`AnalystNarrative` has no price fields. Not `stop`, not `target`, not `entry`. A schema that let
the model emit `stop: 520.0` would be a schema that let it emit `stop: 420.0`, and no amount of
checking afterwards makes that safe. Every number reaches the reader by injection from the
structured result; a figure the model types into its prose has to match registered evidence or the
draft is rejected.

What it writes: a headline, an explanation of the fixed decision, four sections (market structure,
technical view, pattern, smart money), bullish and bearish factors, a narrative for each scenario,
its opinion, and a review of your own thesis.

## The guardrails

Three checks run on every draft. They are the reason an LLM is allowed near this product at all,
and they are tested as attacks — 32 adversarial cases in `tests/test_guardrails.py`, each a draft a
real model could plausibly return.

**Grounding** (`guardrails/grounding.py`). Every numeric token is extracted and matched against the
values the pipeline registered. Matching is *precision-aware*: `620`, `620.0` and `620.00` all mean
the evidence's `620.0`, while **`620.4` does not** — it reads exactly as convincingly and is a
different price. Periods (`50-period`, `14-day`), indicator settings (`EMA 50`, `MACD 12,26,9`),
ordinals, dates, citations and small counts are not prices and are excluded.

**Language** (`guardrails/language.py`). No certainty ("will rally", "guaranteed", "risk-free") —
a setup is a probability, and a trader who reads a promise sizes differently. No sentence that
contradicts the decision the risk gates reached, because the reader follows the sentence, not the
field. No claims about institutional intent: an order block is a rule about candles, not a window
into somebody's book.

**Completeness** (`guardrails/completeness.py`). Every section written and not `"N/A"`; both
scenarios present; the opinion filled; and every cited id real. Citations get the strictest
treatment — an id that does not resolve is worse than no citation, because the point of printing
`[E7]` is that a reader can follow it.

## Draft, repair, fall back

```
draft ─► guardrails ─┬─ clean ──────────────► narrative_source = "llm"
                     └─ violations ─► repair ─┬─ clean ─► "llm"
                                              └─ still bad ─► template
```

One repair, with the violations spelled out, then the template. A second repair costs another GPU
minute to fix a model that has already shown it cannot follow the rules for this input, and the
template is not a failure state: it is the Phase 4A report, built from the same verified facts.

**Nothing here can fail an analysis.** Ollama down, a timeout, malformed JSON, a draft that will
not come clean — every path ends at the template and the run completes. When that happens the
analysis carries a warning saying so.

## What tuning this actually taught

Getting a 4B model to first pass took five rounds, and four of the five fixes were bugs in *this
code*, not the model's failings:

| Symptom | Cause |
|---|---|
| `"E10: Bos (bullish) at 535.00" does not exist` | The model copied the evidence line back, because that is how the prompt prints it. The id was right. Citations are now normalised to the bare id rather than failing the report over punctuation. |
| `"548.10" is not in the evidence` | Scenario targets and plan prices were shown in the prompt but absent from `allowed_values`. The prompt printed a number and then rejected the report for repeating it. |
| `"0.93" is not in the evidence` | Same class: pattern confidences appear in evidence *text* but not in its `value` field. |
| Both scenarios "missing" | Ollama's grammar does not enforce `minItems`, and a Pydantic field with a default is one that gets filled silently when the model omits it — so an absent list became an empty list that never failed validation. `scenarios` and `user_thesis_review` are now required with no default. |
| Stream died at 120 s | The NDJSON stream had no heartbeat; it was written when "the deterministic stages finish in under a second". A stage that holds the GPU for minutes reads as a dead socket to Laravel. |

The one genuine model limitation left is that qwen3:4b needs the schema to do the work — constrain
`name` to a `Literal`, make fields required — rather than being asked nicely in the prompt.

## Performance

Measured on the gpu_4gb profile (RTX 3050 Laptop, qwen3:4b), same prompt:

| `num_ctx` | Time | Throughput |
|---|---|---|
| 16,384 | 86.9 s | 8.0 tok/s |
| 4,096 | 56.7 s | 14.3 tok/s |

The prompt is ~1,400 tokens and the report ~1,000, so a 16k window buys nothing and costs KV cache
a 4 GB card pays for by spilling layers to the CPU. `num_ctx` is therefore a per-profile setting in
`config/models.yaml`, not a constant. End-to-end a full analysis with the analyst runs about 100 s.

The model is kept resident for ten minutes between analyses (`keep_alive`); a cold load costs more
than the generation.

## Settings

| Variable | Default | Meaning |
|---|---|---|
| `ANALYST_ENABLED` | `true` | Off means every report is the template. No model call. |
| `ANALYST_TIMEOUT_SECONDS` | `600` | The one long call in the pipeline; the stream heartbeats meanwhile. |
| `num_ctx` (per profile) | 4096 on gpu_4gb | In `config/models.yaml`, sized to the hardware. |

**Tests never touch a GPU.** `conftest.py` sets `analyst_enabled: false`, because a suite whose
runtime depends on how busy a graphics card is stops being a test suite. Turning it on once took
the engine tests from 5 seconds to 9 minutes.

## Where it is not finished

- **The Markdown report (P4C-08)** still renders from the template even when `narrative_source` is
  `llm`. The prose is stored in `report.narrative` and the API serves it; the Markdown rendering
  of it is the next step.
- **The eval harness (P4C-10)** — 20 Tier A cases through the real model, measuring schema-valid
  rate, pre- and post-repair violations and latency — is not built. Until it is, "how often does
  the analyst pass first time" is an anecdote rather than a number.
