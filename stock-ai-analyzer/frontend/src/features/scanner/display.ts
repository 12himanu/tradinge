/**
 * How the scanner words and colours what it found.
 *
 * Two rules carried over from the analysis report: direction is never colour alone (every bullish
 * or bearish marker also has a glyph and a word), and nothing is phrased as a forecast. A setup
 * score is called a rank, a base rate is called what happened before.
 */

import type { BaseRate, RangeZone, SignalKind } from "@/lib/api/types";

export const SIGNAL_LABEL: Record<SignalKind, string> = {
  trap: "Trap",
  opportunity: "Opportunity",
  context: "Context",
};

/**
 * Traps are amber rather than red: they are a reason to look harder, not an alarm. Opportunities
 * are deliberately not green-as-go — the decision engine, not a badge, decides whether to act.
 */
export const SIGNAL_TONE: Record<SignalKind, string> = {
  trap: "border-amber-600/40 bg-amber-500/10 text-amber-700 dark:text-amber-300",
  opportunity: "border-emerald-600/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300",
  context: "border-muted-foreground/30 bg-muted text-muted-foreground",
};

export const SIGNAL_GLYPH: Record<SignalKind, string> = {
  trap: "⚠",
  opportunity: "◆",
  context: "·",
};

export const ZONE_LABEL: Record<RangeZone, string> = {
  premium: "Premium",
  equilibrium: "Equilibrium",
  discount: "Discount",
};

export const ZONE_EXPLANATION: Record<RangeZone, string> = {
  premium: "Price is in the expensive third of its range. Buying here pays up for the move.",
  equilibrium: "Price is mid-range, where it says least about value.",
  discount: "Price is in the cheap third of its range.",
};

export const ZONE_TONE: Record<RangeZone, string> = {
  premium: "border-rose-600/40 bg-rose-500/10 text-rose-700 dark:text-rose-300",
  equilibrium: "border-muted-foreground/30 bg-muted text-muted-foreground",
  discount: "border-emerald-600/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300",
};

/** The structural vocabulary, in plain English, for anyone who does not speak SMC. */
export const SIGNAL_TYPE_LABEL: Record<string, string> = {
  liquidity_sweep: "Liquidity sweep",
  equal_highs: "Equal highs",
  equal_lows: "Equal lows",
  choch: "Change of character",
  bos: "Break of structure",
  fvg: "Fair value gap",
  order_block: "Order block",
  dealing_range: "Range location",
};

export function signalTypeLabel(type: string | null | undefined): string {
  return (type ? SIGNAL_TYPE_LABEL[type] : undefined) ?? titleCase(type);
}

export function titleCase(value: string | null | undefined): string {
  // Guarded because this renders vendor-shaped data: a field that turns out to be absent should
  // leave a dash in one cell, not throw and take the whole drawer down with it.
  if (!value) return "—";

  return value
    .split(/[_\s]+/)
    .filter(Boolean)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ");
}

/**
 * How much weight a base rate deserves.
 *
 * Sample size is the whole story with these: a rate measured on nine occurrences is a coincidence
 * with a percent sign on it, and the page should say so rather than let a big number speak.
 */
export function evidenceWeight(rate: BaseRate): { label: string; tone: string } {
  if (rate.sample_size >= 100) {
    return { label: "Strong sample", tone: "text-foreground" };
  }
  if (rate.sample_size >= 30) {
    return { label: "Fair sample", tone: "text-muted-foreground" };
  }
  return { label: "Thin sample — treat as noise", tone: "text-amber-700 dark:text-amber-300" };
}

/**
 * A base rate is only interesting where it departs from a coin flip, and the two-barrier race with
 * ties counted against means the neutral point sits a little under 50%.
 */
export function edgeOf(rate: BaseRate): number {
  return rate.favourable_rate - rate.adverse_rate;
}

export function percent(value: number | null | undefined, digits = 0): string {
  if (value === null || value === undefined) return "—";
  return `${(value * 100).toFixed(digits)}%`;
}

export function price(value: number | null | undefined): string {
  if (value === null || value === undefined) return "—";
  return value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
