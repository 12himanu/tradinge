"use client";

import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";
import type { Interval } from "@/lib/api/types";

/**
 * A rate never appears without its interval and its sample size (P6-07).
 *
 * "67%" from three trades and "67%" from three hundred are different claims, and the page has to
 * make that visible or it teaches the user to trust noise.
 */
export function RateWithInterval({
  rate,
  interval,
  sample,
  label,
}: {
  rate: number | null;
  interval: Interval | null;
  sample: number;
  label?: string;
}) {
  if (rate === null || sample === 0) {
    return (
      <span className="text-muted-foreground" title="Nothing has resolved yet">
        —
      </span>
    );
  }

  return (
    <span className="inline-flex flex-col leading-tight">
      <span className="font-medium tabular-nums">
        {formatRate(rate)}
        {label ? <span className="ml-1 text-xs text-muted-foreground">{label}</span> : null}
      </span>
      <span className="text-xs tabular-nums text-muted-foreground">
        {interval ? `${formatRate(interval.low)}–${formatRate(interval.high)} · ` : ""}n={sample}
      </span>
    </span>
  );
}

export function formatRate(value: number | null): string {
  return value === null ? "—" : `${Math.round(value * 100)}%`;
}

export function formatR(value: number | null): string {
  if (value === null) return "—";
  return `${value >= 0 ? "+" : ""}${value.toFixed(2)}R`;
}

/** Colour is never the only signal: R also carries its sign. */
export function RValue({ value }: { value: number | null }) {
  if (value === null) return <span className="text-muted-foreground">—</span>;

  return (
    <span
      className={cn(
        "tabular-nums",
        value > 0 && "text-emerald-700 dark:text-emerald-300",
        value < 0 && "text-rose-700 dark:text-rose-300",
      )}
    >
      {formatR(value)}
    </span>
  );
}

/**
 * Shown next to any statistic built on fewer than 30 resolved outcomes.
 */
export function SampleWarning({ sufficient, sample }: { sufficient: boolean; sample: number }) {
  if (sufficient) return null;

  return (
    <Badge variant="outline" className="font-normal text-muted-foreground">
      {sample === 0 ? "No data yet" : `Only ${sample} resolved — not yet meaningful`}
    </Badge>
  );
}

/**
 * The R distribution, drawn as plain divs. A histogram of outcomes is the most honest single
 * picture of a strategy: it shows the losses at their real size.
 */
export function RHistogram({ bins }: { bins: { from: number; to: number; count: number }[] }) {
  if (bins.length === 0) {
    return <p className="text-sm text-muted-foreground">No resolved trades to plot yet.</p>;
  }

  const max = Math.max(...bins.map((bin) => bin.count), 1);

  return (
    <div className="grid gap-1" role="img" aria-label={describeHistogram(bins)}>
      {bins.map((bin) => (
        <div key={`${bin.from}-${bin.to}`} className="flex items-center gap-2 text-xs">
          <span className="w-20 shrink-0 text-right tabular-nums text-muted-foreground">
            {bin.from.toFixed(1)} to {bin.to.toFixed(1)}
          </span>
          <div className="h-4 flex-1 overflow-hidden rounded-sm bg-muted">
            <div
              className={cn(
                "h-full rounded-sm",
                bin.to <= 0 ? "bg-rose-500/70" : "bg-emerald-500/70",
              )}
              style={{ width: `${(bin.count / max) * 100}%` }}
            />
          </div>
          <span className="w-8 shrink-0 tabular-nums text-muted-foreground">{bin.count}</span>
        </div>
      ))}
    </div>
  );
}

function describeHistogram(bins: { from: number; to: number; count: number }[]): string {
  const total = bins.reduce((sum, bin) => sum + bin.count, 0);
  const losers = bins.filter((bin) => bin.to <= 0).reduce((sum, bin) => sum + bin.count, 0);
  return `Distribution of ${total} resolved trades by R multiple; ${losers} at or below zero.`;
}
