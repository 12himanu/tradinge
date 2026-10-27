"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { BIAS_GLYPH, BIAS_TONE } from "@/features/analysis/display";
import type { ScanFactor } from "@/lib/api/types";
import { cn } from "@/lib/utils";

import { consensus, DIRECTION_WORD, readFamilies, strengthWord } from "./families";

/**
 * What each kind of analysis says, side by side (P5-16).
 *
 * The point of splitting them is disagreement. A chart where trend, momentum, structure and volume
 * all lean the same way is a different proposition from one where momentum has turned and the
 * trend has not noticed yet, and a single blended score hides exactly that. Every line is the
 * engine's own reason for the factor; nothing is re-interpreted here.
 */
export function AnalysisBreakdown({ factors }: { factors: ScanFactor[] | undefined }) {
  const reads = readFamilies(factors);
  const judged = reads.filter((read) => read.value !== null);
  const tally = consensus(judged);

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">By type of analysis</CardTitle>
        <CardDescription>
          {judged.length === 0
            ? "None of the factors could be judged on this data."
            : `${tally.bullish} of ${judged.length} lean up, ${tally.bearish} lean down, ${tally.neutral} take no side. Where they disagree is the part worth reading.`}
        </CardDescription>
      </CardHeader>

      <CardContent className="grid gap-3">
        {reads.map((read) => (
          <div key={read.family.key} className="grid gap-1.5 rounded-lg border p-3">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
              <h3 className="font-medium">{read.family.label}</h3>
              <p className={cn("text-sm tabular-nums", BIAS_TONE[read.direction])}>
                <span aria-hidden className="mr-1">
                  {BIAS_GLYPH[read.direction]}
                </span>
                {read.value === null
                  ? "Not enough data"
                  : `${DIRECTION_WORD[read.direction]} — ${strengthWord(read.value)}`}
              </p>
            </div>

            <p className="text-xs text-muted-foreground">{read.family.scope}</p>

            {read.reasons.length > 0 ? (
              <ul className="grid gap-1 pt-1 text-sm">
                {read.reasons.map((reason) => (
                  <li key={reason.label} className="flex gap-2">
                    <span className="text-muted-foreground">{reason.label}:</span>
                    <span className="min-w-0">{reason.reason}</span>
                  </li>
                ))}
              </ul>
            ) : null}

            {read.unavailable.length > 0 ? (
              <ul className="grid gap-1 text-xs text-muted-foreground">
                {read.unavailable.map((reason) => (
                  <li key={reason}>Not judged: {reason}</li>
                ))}
              </ul>
            ) : null}
          </div>
        ))}
      </CardContent>
    </Card>
  );
}
