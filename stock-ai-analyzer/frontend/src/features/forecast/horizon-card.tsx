"use client";

import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { BIAS_GLYPH } from "@/features/analysis/display";
import { edgeOf, evidenceWeight, percent, price, titleCase } from "@/features/scanner/display";
import type { BaseRate, HorizonOutlook } from "@/lib/api/types";
import { cn } from "@/lib/utils";

/**
 * One horizon — tomorrow, or the week — stated as what has happened before, never as a forecast.
 *
 * The three numbers are deliberately unglamorous. "Higher 51% of the time" is the baseline any
 * signal has to beat, and printing it next to a base rate is the fastest way to see whether a
 * setup is an edge or just an opinion with a chart attached. The band is a percentile range, so it
 * comes with its own caveat: a fifth of moves finished outside it.
 */
export function HorizonCard({ horizon }: { horizon: HorizonOutlook }) {
  const measured = horizon.up_rate !== null;
  // Guarded because this renders stored JSON: a scan filed by an older engine should leave one
  // section empty, not take the page down.
  const rates = horizon.base_rates ?? [];

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex flex-wrap items-baseline justify-between gap-2 text-base">
          <span>{titleCase(horizon.label)}</span>
          <span className="text-sm font-normal text-muted-foreground">
            {horizon.bars === 1 ? "1 bar" : `${horizon.bars} bars`}
          </span>
        </CardTitle>
        <CardDescription>
          {measured
            ? `Measured on ${horizon.sample.toLocaleString()} past ${horizon.bars}-bar moves of this stock.`
            : `Only ${horizon.sample} past moves — too few to measure anything from.`}
        </CardDescription>
      </CardHeader>

      <CardContent className="grid gap-4">
        {measured ? (
          <>
            <dl className="grid gap-3 sm:grid-cols-3">
              <Stat
                term="Closed higher"
                value={percent(horizon.up_rate)}
                note="of the time, historically"
              />
              <Stat
                term="Ordinary move"
                value={`±${(horizon.typical_move_pct ?? 0).toFixed(2)}%`}
                note="median size, direction aside"
              />
              <Stat
                term="Middle 80% landed"
                value={`${price(horizon.band_low)} – ${price(horizon.band_high)}`}
                note={`from ${price(horizon.reference_price)}`}
              />
            </dl>

            <p className="text-xs text-muted-foreground">
              One move in five finished outside that band, so it is a sense of scale — not a target
              and not a limit.
            </p>
          </>
        ) : null}

        <div className="grid gap-2">
          <h4 className="text-sm font-medium">
            What today&apos;s candlestick signals were worth over {horizon.bars}{" "}
            {horizon.bars === 1 ? "bar" : "bars"}
          </h4>
          {rates.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              No candlestick signal is firing on the last bar, so there is nothing to look up. The
              numbers above are the stock with no signal at all.
            </p>
          ) : (
            <ul className="grid gap-2">
              {rates.map((rate) => (
                <BaseRateRow key={`${rate.type}-${rate.direction}`} rate={rate} />
              ))}
            </ul>
          )}
        </div>
      </CardContent>
    </Card>
  );
}

function Stat({ term, value, note }: { term: string; value: string; note: string }) {
  return (
    <div className="grid gap-0.5">
      <dt className="text-xs text-muted-foreground">{term}</dt>
      <dd className="text-lg font-semibold tabular-nums tracking-tight">{value}</dd>
      <dd className="text-xs text-muted-foreground">{note}</dd>
    </div>
  );
}

/**
 * A base rate with everything needed to discount it: the sample it rests on, and how far it sits
 * from a coin flip. The edge, not the raw rate, is the number that means something.
 */
function BaseRateRow({ rate }: { rate: BaseRate }) {
  const weight = evidenceWeight(rate);
  const edge = edgeOf(rate);

  return (
    <li className="rounded-md border p-3">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <span className="text-sm font-medium">
          <span aria-hidden className="mr-1">
            {BIAS_GLYPH[rate.direction]}
          </span>
          {titleCase(rate.type)}
          <span className="sr-only"> ({rate.direction})</span>
        </span>
        <Badge variant="outline" className="tabular-nums">
          {percent(rate.favourable_rate)} reached +1 ATR first
        </Badge>
      </div>
      <p className={cn("mt-1 text-xs", weight.tone)}>
        {rate.favourable} of {rate.sample_size} times, against {rate.adverse} that hit −1 ATR first
        and {rate.unresolved} that reached neither. {weight.label}.
      </p>
      <p className="mt-1 text-xs text-muted-foreground">
        {edge > 0.05
          ? `That is ${percent(edge)} better than it went against — a real edge if the sample holds.`
          : edge < -0.05
            ? `It went against more often than for: ${percent(-edge)} the wrong way.`
            : "Close enough to even that it settles nothing."}
      </p>
    </li>
  );
}
