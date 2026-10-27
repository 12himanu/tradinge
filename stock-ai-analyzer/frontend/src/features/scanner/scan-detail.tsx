"use client";

import { useEffect, useRef } from "react";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";
import { BIAS_GLYPH, BIAS_TONE, DECISION_LABEL, DECISION_TONE } from "@/features/analysis/display";
import type { BaseRate, MarketScan, ScanSignal } from "@/lib/api/types";

import {
  edgeOf,
  evidenceWeight,
  percent,
  price,
  signalTypeLabel,
  SIGNAL_GLYPH,
  SIGNAL_TONE,
  titleCase,
  ZONE_EXPLANATION,
  ZONE_LABEL,
  ZONE_TONE,
} from "./display";
import { useStockScan } from "./queries";

type Props = {
  stockId: number | null;
  symbol: string | null;
  onClose: () => void;
};

/**
 * Everything the engine saw on one stock, in the order a person actually reads a chart: where the
 * market stands, what could go wrong, what could go right, what it has been worth before, and only
 * then the arithmetic of a trade.
 */
export function ScanDetail({ stockId, symbol, onClose }: Props) {
  const scan = useStockScan(stockId);
  const panel = useRef<HTMLDivElement>(null);

  // Escape closes, and focus moves into the panel so a keyboard user is not left behind the table.
  useEffect(() => {
    if (stockId === null) return;
    panel.current?.focus();
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [stockId, onClose]);

  if (stockId === null) return null;

  return (
    <div className="fixed inset-0 z-50 flex justify-end">
      <button
        type="button"
        aria-label="Close details"
        className="absolute inset-0 bg-background/60 backdrop-blur-sm"
        onClick={onClose}
      />
      <div
        ref={panel}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-label={`${symbol ?? "Stock"} scan detail`}
        className="relative flex h-full w-full max-w-xl flex-col overflow-y-auto border-l bg-background shadow-xl outline-none"
      >
        <div className="sticky top-0 z-10 flex items-center justify-between gap-4 border-b bg-background/95 px-5 py-4 backdrop-blur">
          <div className="min-w-0">
            <h2 className="truncate text-lg font-semibold tracking-tight">{symbol ?? "—"}</h2>
            <p className="truncate text-sm text-muted-foreground">
              {scan.data?.stock.name ?? "Loading…"}
            </p>
          </div>
          <Button variant="ghost" size="sm" onClick={onClose}>
            Close
          </Button>
        </div>

        <div className="grid gap-6 px-5 py-5">
          {scan.isPending ? <DetailSkeleton /> : null}
          {scan.isError ? (
            <Alert variant="destructive">
              <AlertTitle>Could not load the reading</AlertTitle>
              <AlertDescription>{scan.error.message}</AlertDescription>
            </Alert>
          ) : null}
          {scan.data ? <ScanReading scan={scan.data} /> : null}
        </div>
      </div>
    </div>
  );
}

/**
 * The engine's whole reading of one stock, in the order a person reads a chart: where the market
 * stands, what could go wrong, what could go right, what it has been worth before, and only then
 * the arithmetic of a trade.
 *
 * Exported because the stock page shows the same thing full-width; the drawer is just one frame
 * around it.
 */
export function ScanReading({ scan }: { scan: MarketScan }) {
  const traps = (scan.signals ?? []).filter((signal) => signal.kind === "trap");
  const opportunities = (scan.signals ?? []).filter((signal) => signal.kind === "opportunity");
  const context = (scan.signals ?? []).filter((signal) => signal.kind === "context");

  return (
    <>
      <Standing scan={scan} />

      <Section
        title="Traps"
        blurb="Reasons the obvious read may be the wrong one."
        empty="Nothing structural is working against the read."
        count={traps.length}
      >
        {traps.map((signal, index) => (
          <SignalRow key={`${signal.type}-${index}`} signal={signal} />
        ))}
      </Section>

      <Section
        title="Opportunities"
        blurb="Places the move could be joined, in the direction of the bias."
        empty="No unfilled gaps or untested blocks in the direction of the bias."
        count={opportunities.length}
      >
        {opportunities.map((signal, index) => (
          <SignalRow key={`${signal.type}-${index}`} signal={signal} />
        ))}
      </Section>

      <BaseRates rates={scan.base_rates ?? []} />

      <Plans scan={scan} />

      <Factors scan={scan} />

      {context.length > 0 ? (
        <Section title="Context" blurb="Real, but neither a trap nor an opening." count={context.length}>
          {context.map((signal, index) => (
            <SignalRow key={`${signal.type}-${index}`} signal={signal} />
          ))}
        </Section>
      ) : null}

      {scan.warnings && scan.warnings.length > 0 ? (
        <Alert>
          <AlertTitle>Data caveats</AlertTitle>
          <AlertDescription>
            <ul className="list-disc space-y-1 pl-4">
              {scan.warnings.map((warning) => (
                <li key={warning}>{warning}</li>
              ))}
            </ul>
          </AlertDescription>
        </Alert>
      ) : null}

      <p className="text-xs text-muted-foreground">
        Read from {scan.bar_count.toLocaleString()} stored daily bars, engine{" "}
        {scan.engine_version ?? "unknown"}. This is a reading, not advice, and it files no
        prediction — run a full analysis to put it on your record.
      </p>
    </>
  );
}

function Standing({ scan }: { scan: MarketScan }) {
  return (
    <section className="grid gap-3">
      <div className="flex flex-wrap items-center gap-2">
        {scan.bias ? (
          <Badge variant="outline" className={cn("gap-1", BIAS_TONE[scan.bias])}>
            <span aria-hidden>{BIAS_GLYPH[scan.bias]}</span>
            {titleCase(scan.bias)}
          </Badge>
        ) : null}
        {scan.decision ? (
          <Badge variant="outline" className={DECISION_TONE[scan.decision]}>
            {DECISION_LABEL[scan.decision]}
          </Badge>
        ) : null}
        {scan.range_zone ? (
          <Badge variant="outline" className={ZONE_TONE[scan.range_zone]}>
            {ZONE_LABEL[scan.range_zone]}
            {scan.range_position !== null ? ` · ${percent(scan.range_position)} up range` : ""}
          </Badge>
        ) : null}
      </div>

      <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
        <Stat label="Last price" value={price(scan.last_price)} />
        <Stat label="Structure" value={scan.structure ? titleCase(scan.structure) : "—"} />
        <Stat
          label="Last event"
          value={scan.last_event ? titleCase(scan.last_event.replace(/_/g, " ")) : "—"}
        />
      </dl>

      {scan.decision_rationale ? (
        <p className="rounded-md border bg-muted/40 px-3 py-2 text-sm">{scan.decision_rationale}</p>
      ) : null}

      {scan.range_zone ? (
        <p className="text-xs text-muted-foreground">{ZONE_EXPLANATION[scan.range_zone]}</p>
      ) : null}

      <div className="rounded-md border px-3 py-2">
        <div className="flex items-baseline justify-between">
          <span className="text-sm font-medium">Setup score</span>
          <span className="text-lg font-semibold tabular-nums">{scan.setup_score ?? "—"}</span>
        </div>
        <p className="mt-1 text-xs text-muted-foreground">
          A rank across the factors below, not a probability. Nothing here says how likely a trade
          is to work; the base rates do that, and only for what has happened before.
        </p>
      </div>
    </section>
  );
}

function BaseRates({ rates }: { rates: BaseRate[] }) {
  if (rates.length === 0) {
    return (
      <Section
        title="What happened before"
        blurb="Every earlier time these signals fired on this symbol."
        empty="Not enough history on this symbol to measure anything honestly."
        count={0}
      />
    );
  }

  const horizon = rates[0]?.horizon_bars;

  return (
    <section className="grid gap-3">
      <header>
        <h3 className="text-sm font-semibold tracking-tight">What happened before</h3>
        <p className="text-xs text-muted-foreground">
          Every earlier time each signal fired on this symbol, raced forward {horizon} bars to a
          barrier one ATR either side. A tie counts as a loss. This is the only probability on the
          page.
        </p>
      </header>

      <div className="grid gap-2">
        {rates.map((rate) => {
          const weight = evidenceWeight(rate);
          const edge = edgeOf(rate);
          return (
            <div key={`${rate.type}-${rate.direction}`} className="rounded-md border px-3 py-2">
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-sm font-medium">
                  {signalTypeLabel(rate.type)}{" "}
                  <span className="text-muted-foreground">({rate.direction})</span>
                </span>
                <span className="text-sm tabular-nums">
                  {percent(rate.favourable_rate, 1)} went its way
                </span>
              </div>

              <div
                className="mt-2 flex h-2 w-full overflow-hidden rounded-full bg-muted"
                role="img"
                aria-label={`${rate.favourable} favourable, ${rate.adverse} adverse, ${rate.unresolved} unresolved`}
              >
                <span className="bg-emerald-500/70" style={{ width: `${rate.favourable_rate * 100}%` }} />
                <span className="bg-rose-500/70" style={{ width: `${rate.adverse_rate * 100}%` }} />
              </div>

              <div className="mt-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs text-muted-foreground">
                <span>
                  {rate.favourable} for · {rate.adverse} against · {rate.unresolved} neither ·{" "}
                  <span className="tabular-nums">n={rate.sample_size}</span>
                </span>
                <span className={weight.tone}>{weight.label}</span>
              </div>

              <p className="mt-1 text-xs text-muted-foreground">
                {Math.abs(edge) < 0.05
                  ? "Effectively a coin flip on this symbol: no edge either way."
                  : edge > 0
                    ? `Leaned its way by ${percent(edge, 1)} of occurrences.`
                    : `Leaned against it by ${percent(-edge, 1)} of occurrences.`}
                {rate.median_barrier_pct !== null
                  ? ` Barrier was about ${rate.median_barrier_pct.toFixed(1)}% of price.`
                  : ""}
              </p>
            </div>
          );
        })}
      </div>
    </section>
  );
}

function Plans({ scan }: { scan: MarketScan }) {
  const plans = scan.plans ?? [];
  if (plans.length === 0) return null;

  return (
    <Section
      title="Trade plans"
      blurb="Entry, stop and targets, with reward measured after NEPSE fees."
      count={plans.length}
    >
      {plans.map((plan, index) => (
        <div key={`${plan.entry_type}-${index}`} className="rounded-md border px-3 py-2">
          <div className="flex flex-wrap items-baseline justify-between gap-2">
            <span className="text-sm font-medium">
              {titleCase(plan.entry_type)}
              <span className="ml-2 text-xs font-normal text-muted-foreground">{plan.direction}</span>
            </span>
            <Badge variant="outline" className={plan.viable ? SIGNAL_TONE.opportunity : SIGNAL_TONE.context}>
              {plan.viable ? "Viable" : "Not viable"}
            </Badge>
          </div>

          <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4">
            <Stat label="Entry" value={price(plan.entry_price)} />
            <Stat label="Stop" value={price(plan.stop_price)} />
            <Stat
              label={plan.targets.length > 1 ? "Targets" : "Target"}
              value={plan.targets.map((target) => price(target)).join(" · ") || "—"}
            />
            {/* Net is the one that matters: it is what the decision gate reads, after NEPSE fees. */}
            <Stat label="R:R net" value={`${plan.rr_net.toFixed(2)} (${plan.rr_gross.toFixed(2)} gross)`} />
          </dl>

          <p className="mt-2 text-xs text-muted-foreground">
            Risk {plan.risk_pct.toFixed(2)}% · reward {plan.reward_pct.toFixed(2)}% ·{" "}
            {plan.quantity.toLocaleString()} shares, {plan.position_pct.toFixed(1)}% of the account
          </p>

          {plan.reasons.map((reason) => (
            <p key={reason} className="mt-1 text-xs text-muted-foreground">
              {reason}
            </p>
          ))}

          {plan.viable_at_price !== null && !plan.viable ? (
            <p className="mt-1 text-xs text-muted-foreground">
              Would be viable at {price(plan.viable_at_price)}.
            </p>
          ) : null}
        </div>
      ))}
    </Section>
  );
}

function Factors({ scan }: { scan: MarketScan }) {
  const factors = (scan.factors ?? []).filter((factor) => factor.available);
  if (factors.length === 0) return null;

  return (
    <Section title="What built the score" blurb="Each factor and the weight it carried." count={factors.length}>
      {factors.map((factor) => (
        <div key={factor.key} className="grid gap-1 rounded-md border px-3 py-2">
          <div className="flex items-baseline justify-between gap-2">
            <span className="text-sm font-medium">{factor.label}</span>
            <span className="text-xs tabular-nums text-muted-foreground">
              {percent(factor.effective_weight)} weight
            </span>
          </div>
          {factor.reason ? <p className="text-xs text-muted-foreground">{factor.reason}</p> : null}
        </div>
      ))}
    </Section>
  );
}

function SignalRow({ signal }: { signal: ScanSignal }) {
  const zone =
    signal.price_low !== null && signal.price_high !== null && signal.price_low !== signal.price_high
      ? `${price(signal.price_low)} – ${price(signal.price_high)}`
      : signal.price_low !== null
        ? price(signal.price_low)
        : null;

  return (
    <div className="grid gap-1 rounded-md border px-3 py-2">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <span className="text-sm font-medium">
          <span aria-hidden className="mr-1">
            {SIGNAL_GLYPH[signal.kind]}
          </span>
          {signalTypeLabel(signal.type)}
        </span>
        {zone ? <span className="text-xs tabular-nums text-muted-foreground">{zone}</span> : null}
      </div>
      <p className="text-xs text-muted-foreground">{signal.headline}</p>
    </div>
  );
}

function Section({
  title,
  blurb,
  count,
  empty,
  children,
}: {
  title: string;
  blurb: string;
  count: number;
  empty?: string;
  children?: React.ReactNode;
}) {
  return (
    <section className="grid gap-2">
      <header>
        <h3 className="text-sm font-semibold tracking-tight">
          {title}
          {count > 0 ? <span className="ml-2 text-muted-foreground">{count}</span> : null}
        </h3>
        <p className="text-xs text-muted-foreground">{blurb}</p>
      </header>
      {count === 0 && empty ? (
        <p className="rounded-md border border-dashed px-3 py-3 text-xs text-muted-foreground">
          {empty}
        </p>
      ) : (
        <div className="grid gap-2">{children}</div>
      )}
      <Separator className="mt-1" />
    </section>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="tabular-nums">{value}</dd>
    </div>
  );
}

function DetailSkeleton() {
  return (
    <div className="grid gap-3">
      <Skeleton className="h-6 w-40" />
      <Skeleton className="h-20 w-full" />
      <Skeleton className="h-32 w-full" />
      <Skeleton className="h-32 w-full" />
    </div>
  );
}
