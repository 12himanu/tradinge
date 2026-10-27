/**
 * The engine's nine scoring factors, grouped into the kinds of analysis a trader recognises.
 *
 * The scoring config keys are engine vocabulary (`trend_ema_alignment`, `range_location`). Someone
 * asking "what does each type of analysis say about tomorrow" is asking about trend, momentum,
 * structure, volume, patterns and levels — so the factors are grouped under those names, and the
 * engine's own one-line reason for each is what the row actually says. Nothing here re-judges a
 * factor: the direction and the strength are the value the engine already computed.
 */

import type { Bias, ScanFactor } from "@/lib/api/types";

export type FamilyKey = "trend" | "momentum" | "structure" | "volume" | "patterns" | "levels";

export type Family = {
  key: FamilyKey;
  label: string;
  /** What this kind of analysis can and cannot tell you about the next few days. */
  scope: string;
  factorKeys: string[];
};

export const FAMILIES: Family[] = [
  {
    key: "trend",
    label: "Trend",
    scope:
      "Where price sits against its moving averages. Slow to turn, so it says more about the week than about tomorrow.",
    factorKeys: ["trend_ema_alignment", "price_location"],
  },
  {
    key: "momentum",
    label: "Momentum",
    scope:
      "RSI regime and MACD. The fastest of these to change, and the one most often wrong at a turn.",
    factorKeys: ["momentum_rsi", "momentum_macd"],
  },
  {
    key: "structure",
    label: "Market structure",
    scope:
      "Higher highs or lower lows, and where price sits in its dealing range. What a break would have to break.",
    factorKeys: ["market_structure", "range_location"],
  },
  {
    key: "volume",
    label: "Volume",
    scope: "Whether the moves that made this trend came with participation behind them.",
    factorKeys: ["volume_confirmation"],
  },
  {
    key: "patterns",
    label: "Chart patterns",
    scope:
      "Completed and forming geometry. A pattern says what it would take to confirm, not what will happen.",
    factorKeys: ["pattern"],
  },
  {
    key: "levels",
    label: "Room to move",
    scope: "How far the next level is. A strong setup into a wall is still a bad trade.",
    factorKeys: ["room_to_move"],
  },
];

export type FamilyRead = {
  family: Family;
  /** Weighted mean of the family's available factors, in [-1, 1]. Null when none could be judged. */
  value: number | null;
  direction: Bias;
  /** The engine's own reasons, one per factor that was available. */
  reasons: { label: string; reason: string; value: number }[];
  unavailable: string[];
};

/** Below this a factor is reported as balanced rather than given a side it does not really have. */
const NEUTRAL_BAND = 0.15;

export function readFamilies(factors: ScanFactor[] | undefined): FamilyRead[] {
  const byKey = new Map((factors ?? []).map((factor) => [factor.key, factor]));

  return FAMILIES.map((family) => {
    const present = family.factorKeys
      .map((key) => byKey.get(key))
      .filter((factor): factor is ScanFactor => factor !== undefined);

    const judged = present.filter((factor) => factor.available && factor.value !== null);
    const weight = judged.reduce((sum, factor) => sum + (factor.weight || 1), 0);
    const value =
      judged.length === 0
        ? null
        : judged.reduce((sum, factor) => sum + (factor.value ?? 0) * (factor.weight || 1), 0) /
          (weight || 1);

    return {
      family,
      value,
      direction: directionOf(value),
      reasons: judged.map((factor) => ({
        label: factor.label,
        reason: factor.reason ?? "",
        value: factor.value ?? 0,
      })),
      unavailable: present
        .filter((factor) => !factor.available || factor.value === null)
        .map((factor) => factor.reason || `${factor.label} unavailable`),
    };
  });
}

export function directionOf(value: number | null): Bias {
  if (value === null) return "neutral";
  if (value >= NEUTRAL_BAND) return "bullish";
  if (value <= -NEUTRAL_BAND) return "bearish";
  return "neutral";
}

export const DIRECTION_WORD: Record<Bias, string> = {
  bullish: "Leaning up",
  bearish: "Leaning down",
  neutral: "No side",
};

/** How strongly, in words. A number in [-1, 1] is the engine's, not something to show raw. */
export function strengthWord(value: number | null): string {
  if (value === null) return "not enough data";
  const size = Math.abs(value);
  if (size >= 0.66) return "strongly";
  if (size >= 0.33) return "clearly";
  if (size >= NEUTRAL_BAND) return "mildly";
  return "barely";
}

/** Agreement across the families, which is what makes a read worth anything. */
export function consensus(reads: FamilyRead[]): {
  bullish: number;
  bearish: number;
  neutral: number;
} {
  return reads.reduce(
    (tally, read) => ({ ...tally, [read.direction]: tally[read.direction] + 1 }),
    { bullish: 0, bearish: 0, neutral: 0 },
  );
}
