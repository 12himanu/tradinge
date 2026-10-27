/**
 * How analysis values are worded and coloured (P5-01).
 *
 * Direction is never carried by colour alone: every bullish/bearish signal also has a glyph and a
 * word, so the report stays readable for colour-blind users and in print.
 */

import type { Bias, DataTier, DecisionAction, TradePlan } from "@/lib/api/types";

export const TIER_LABEL: Record<DataTier, string> = {
  ohlcv: "Tier A · real OHLCV",
  digitized: "Tier B · read from the chart",
  vision_only: "Tier C · vision only",
};

export const TIER_EXPLANATION: Record<DataTier, string> = {
  ohlcv: "Indicators and levels are computed from imported price data, so the numbers are exact.",
  digitized:
    "Prices were reconstructed from the screenshot's axis. Levels are approximate and the setup score is capped.",
  vision_only:
    "No price data could be used, so nothing numeric is claimed: no setup score and no trade plan.",
};

export const DECISION_LABEL: Record<DecisionAction, string> = {
  buy: "Buy",
  wait: "Wait",
  hold: "Hold",
  sell: "Sell",
  avoid: "Avoid",
};

/** Tailwind classes per decision. Wait and avoid are deliberately quiet, not alarming. */
export const DECISION_TONE: Record<DecisionAction, string> = {
  buy: "border-emerald-600/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300",
  hold: "border-sky-600/40 bg-sky-500/10 text-sky-700 dark:text-sky-300",
  wait: "border-amber-600/40 bg-amber-500/10 text-amber-700 dark:text-amber-300",
  sell: "border-rose-600/40 bg-rose-500/10 text-rose-700 dark:text-rose-300",
  avoid: "border-muted-foreground/30 bg-muted text-muted-foreground",
};

export const BIAS_GLYPH: Record<Bias, string> = {
  bullish: "▲",
  bearish: "▼",
  neutral: "●",
};

export const BIAS_TONE: Record<Bias, string> = {
  bullish: "text-emerald-700 dark:text-emerald-300",
  bearish: "text-rose-700 dark:text-rose-300",
  neutral: "text-muted-foreground",
};

export function titleCase(value: string): string {
  const words = value.replace(/_/g, " ");
  return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * A price, in the exchange's currency. Tier B numbers are approximations and say so, because a
 * digitized level read off pixels is not the same claim as one computed from real bars.
 */
export function formatPrice(
  value: number | null | undefined,
  tier?: DataTier | null,
  currency = "NPR",
): string {
  if (value === null || value === undefined) return "—";
  const formatted = new Intl.NumberFormat("en-US", {
    style: "currency",
    currency,
    currencyDisplay: "narrowSymbol",
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value);
  return tier === "digitized" ? `≈ ${formatted}` : formatted;
}

/** A bare number for tables, where the currency is already in the column header. */
export function formatNumber(value: number | null | undefined, digits = 2): string {
  if (value === null || value === undefined || Number.isNaN(value)) return "—";
  return new Intl.NumberFormat("en-US", {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  }).format(value);
}

export function formatPercent(value: number | null | undefined, digits = 2): string {
  if (value === null || value === undefined) return "—";
  return `${value.toFixed(digits)}%`;
}

export function formatSigned(value: number, digits = 2): string {
  return `${value >= 0 ? "+" : ""}${value.toFixed(digits)}`;
}

export function formatRatio(value: number | null | undefined): string {
  if (value === null || value === undefined) return "—";
  return `1 : ${value.toFixed(2)}`;
}

export function formatDuration(ms: number | null | undefined): string {
  if (ms === null || ms === undefined) return "—";
  if (ms < 1000) return `${ms} ms`;
  const seconds = ms / 1000;
  if (seconds < 60) return `${seconds.toFixed(1)} s`;
  return `${Math.floor(seconds / 60)} min ${Math.round(seconds % 60)} s`;
}

export function planTitle(plan: TradePlan): string {
  return `${titleCase(plan.entry_type)} entry`;
}

/** The engine's stage names, in the order the pipeline runs them (docs/04 §2). */
export const STAGES: { key: string; label: string }[] = [
  { key: "request", label: "Preparing the request" },
  { key: "identity", label: "Checking the chart's symbol" },
  { key: "indicators", label: "Indicators" },
  { key: "structure", label: "Swings and levels" },
  { key: "technical", label: "Technical read" },
  { key: "smc", label: "Market structure" },
  { key: "patterns", label: "Patterns" },
  { key: "scoring", label: "Weighing the evidence" },
  { key: "risk", label: "Trade plans and decision" },
  { key: "report", label: "Writing the report" },
];

export function stageLabel(key: string | null): string {
  if (key === null) return "Starting";
  return STAGES.find((stage) => stage.key === key)?.label ?? titleCase(key);
}

/**
 * What went wrong, in words a trader can act on. The engine's codes are precise but terse.
 */
export const ERROR_EXPLANATION: Record<string, string> = {
  ENGINE_UNAVAILABLE:
    "The analysis engine isn't reachable. Check that the Docker stack is running, then try again.",
  ENGINE_BUSY: "The engine is already running other analyses. Try again in a minute.",
  ENGINE_TIMEOUT: "The engine stopped responding partway through. Running it again usually works.",
  INSUFFICIENT_DATA:
    "There isn't enough price history for this symbol and timeframe. Import more bars on the Market data page.",
  NOT_A_CHART: "That image doesn't look like a price chart.",
  // SYMBOL_MISMATCH is deliberately absent: the engine's own message names the company printed on
  // the screenshot and the symbol it was run for, which is the whole content of the answer.
  INVALID_IMAGE_PATH: "The chart image couldn't be read from storage.",
  IMAGE_NOT_FOUND: "The chart image is missing from storage. Upload it again.",
  ANALYSIS_STALLED: "The analysis stopped responding and was timed out. Try running it again.",
  ANALYSIS_NEVER_STARTED:
    "No worker picked the job up. Check that Horizon is running, then try again.",
};

export function explainError(code: string | null, fallback: string | null): string {
  if (code !== null && ERROR_EXPLANATION[code]) return ERROR_EXPLANATION[code];
  return fallback ?? "The analysis failed. Try running it again.";
}
