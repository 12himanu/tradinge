import { describe, expect, it } from "vitest";

import type { ScanFactor } from "@/lib/api/types";

import { consensus, directionOf, readFamilies, strengthWord } from "./families";

function factor(
  key: string,
  value: number | null,
  overrides: Partial<ScanFactor> = {},
): ScanFactor {
  return {
    key,
    label: key,
    value,
    weight: 0.1,
    effective_weight: 0.1,
    contribution: 0,
    available: value !== null,
    reason: `${key} reason`,
    ...overrides,
  };
}

describe("readFamilies", () => {
  it("groups the engine's factor keys under the analysis a trader would name", () => {
    const reads = readFamilies([
      factor("trend_ema_alignment", 1),
      factor("price_location", 1),
      factor("momentum_rsi", -0.7),
    ]);

    const trend = reads.find((read) => read.family.key === "trend");
    const momentum = reads.find((read) => read.family.key === "momentum");

    expect(trend?.direction).toBe("bullish");
    expect(momentum?.direction).toBe("bearish");
    // The disagreement between them is the reason the page splits them at all.
    expect(trend?.reasons).toHaveLength(2);
  });

  it("weights a family by the engine's own factor weights", () => {
    const reads = readFamilies([
      factor("trend_ema_alignment", 1, { weight: 0.15 }),
      factor("price_location", -1, { weight: 0.1 }),
    ]);

    const trend = reads.find((read) => read.family.key === "trend");

    // (1 × 0.15 − 1 × 0.10) / 0.25
    expect(trend?.value).toBeCloseTo(0.2, 5);
  });

  it("reports a family nothing could be judged on rather than calling it neutral", () => {
    const reads = readFamilies([factor("volume_confirmation", null, { reason: "no volume data" })]);

    const volume = reads.find((read) => read.family.key === "volume");

    expect(volume?.value).toBeNull();
    expect(volume?.unavailable).toEqual(["no volume data"]);
  });

  it("always returns every family, so a missing one is visibly missing", () => {
    expect(readFamilies(undefined)).toHaveLength(6);
    expect(readFamilies([]).every((read) => read.value === null)).toBe(true);
  });
});

describe("directionOf", () => {
  it("leaves a barely-off-zero factor without a side", () => {
    expect(directionOf(0.1)).toBe("neutral");
    expect(directionOf(-0.1)).toBe("neutral");
    expect(directionOf(0.15)).toBe("bullish");
    expect(directionOf(-0.15)).toBe("bearish");
    expect(directionOf(null)).toBe("neutral");
  });
});

describe("strengthWord", () => {
  it("says how strongly in words, because the raw number is the engine's", () => {
    expect(strengthWord(0.9)).toBe("strongly");
    expect(strengthWord(-0.4)).toBe("clearly");
    expect(strengthWord(0.2)).toBe("mildly");
    expect(strengthWord(null)).toBe("not enough data");
  });
});

describe("consensus", () => {
  it("counts which way the families lean", () => {
    const reads = readFamilies([
      factor("trend_ema_alignment", 1),
      factor("momentum_rsi", -1),
      factor("pattern", 0),
    ]);

    // Only the three judged families take a side; the rest have no data and read neutral.
    expect(consensus(reads.filter((read) => read.value !== null))).toEqual({
      bullish: 1,
      bearish: 1,
      neutral: 1,
    });
  });
});
