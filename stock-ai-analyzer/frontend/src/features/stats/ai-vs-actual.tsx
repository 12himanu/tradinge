"use client";

import { useQuery } from "@tanstack/react-query";

import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { formatPrice, titleCase } from "@/features/analysis/display";
import { formatDate } from "@/lib/format";
import { cn } from "@/lib/utils";
import type { Analysis, Prediction, PricePath } from "@/lib/api/types";

import { getPricePath } from "./api";
import { RValue } from "./stat-primitives";

const RESULT_TONE: Record<string, string> = {
  win: "border-emerald-600/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300",
  loss: "border-rose-600/40 bg-rose-500/10 text-rose-700 dark:text-rose-300",
  expired: "border-muted-foreground/30 bg-muted text-muted-foreground",
  invalidated: "border-muted-foreground/30 bg-muted text-muted-foreground",
};

/**
 * What the analysis predicted, next to what price did (P6-06).
 *
 * This is the panel that makes the app accountable: it is drawn from bars strictly after the
 * analysis date, and it shows the losers exactly as prominently as the winners.
 */
export function AiVsActual({ analysis }: { analysis: Analysis }) {
  const predictions = analysis.predictions ?? [];
  const path = useQuery<PricePath>({
    queryKey: ["analysis", analysis.id, "price-path"],
    queryFn: ({ signal }) => getPricePath(analysis.id, signal),
    enabled: predictions.length > 0 && analysis.stock !== null,
  });

  if (predictions.length === 0) return null;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-lg">AI vs actual</CardTitle>
        <CardDescription>
          Scored against real bars after{" "}
          {analysis.as_of ? formatDate(analysis.as_of) : "the analysis date"}, win or lose.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-5">
        {predictions.map((prediction) => (
          <PredictionRow key={prediction.id} prediction={prediction} analysis={analysis} />
        ))}

        {path.isPending && predictions.length > 0 ? (
          <Skeleton className="h-24 w-full" />
        ) : path.data && path.data.bars.length > 0 ? (
          <PricePathChart path={path.data} predictions={predictions} tier={analysis.data_tier} />
        ) : (
          <p className="text-sm text-muted-foreground">
            No bars have been imported since this analysis, so there is nothing to score it against
            yet.
          </p>
        )}
      </CardContent>
    </Card>
  );
}

function PredictionRow({ prediction, analysis }: { prediction: Prediction; analysis: Analysis }) {
  const outcome = prediction.outcome;
  const tier = analysis.data_tier;

  return (
    <div className="grid gap-2 rounded-md border p-3">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-medium">{titleCase(prediction.kind)}</span>
        <Badge variant="outline">{titleCase(prediction.status)}</Badge>
        {outcome ? (
          <span
            className={cn(
              "rounded border px-2 py-0.5 text-xs font-medium",
              RESULT_TONE[outcome.result] ?? "border-border text-muted-foreground",
            )}
          >
            {titleCase(outcome.result)}
          </span>
        ) : null}
        {outcome?.ambiguous_bar ? (
          <Badge
            variant="outline"
            title="The stop and the target were both touched inside one bar, so it was counted as a loss."
          >
            Ambiguous bar
          </Badge>
        ) : null}
      </div>

      <Timeline prediction={prediction} />

      <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
        <Field label="Entry">{formatPrice(prediction.entry_price, tier)}</Field>
        <Field label="Stop">{formatPrice(prediction.stop_price, tier)}</Field>
        <Field label="Target">{formatPrice(prediction.target_price_1, tier)}</Field>
        <Field label="Exit">{formatPrice(outcome?.exit_price ?? null, tier)}</Field>
        {outcome ? (
          <>
            <Field label="R multiple">
              <RValue value={outcome.r_multiple} />
            </Field>
            <Field label="Return">
              {outcome.return_pct === null ? "—" : `${outcome.return_pct.toFixed(2)}%`}
            </Field>
            <Field label="Best excursion">
              {outcome.mfe_pct === null ? "—" : `+${outcome.mfe_pct.toFixed(2)}%`}
            </Field>
            <Field label="Worst excursion">
              {outcome.mae_pct === null ? "—" : `−${outcome.mae_pct.toFixed(2)}%`}
            </Field>
          </>
        ) : null}
      </dl>
    </div>
  );
}

/** Pending → active → resolved, with the dates that actually happened. */
function Timeline({ prediction }: { prediction: Prediction }) {
  const steps = [
    { label: "Recorded", at: prediction.as_of, done: true },
    {
      label: "Triggered",
      at: prediction.activated_at ?? prediction.outcome?.triggered_at ?? null,
      done: prediction.activated_at !== null || prediction.outcome?.triggered_at != null,
    },
    {
      label: "Resolved",
      at: prediction.resolved_at ?? prediction.outcome?.exit_at ?? null,
      done: prediction.resolved_at !== null,
    },
  ];

  return (
    <ol className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
      {steps.map((step) => (
        <li key={step.label} className="flex items-center gap-1.5">
          <span
            aria-hidden
            className={cn(
              "size-1.5 rounded-full",
              step.done ? "bg-foreground" : "bg-muted-foreground/40",
            )}
          />
          <span className={cn(step.done && "text-foreground")}>{step.label}</span>
          {step.at ? <span>{formatDate(step.at)}</span> : <span>pending</span>}
        </li>
      ))}
    </ol>
  );
}

/**
 * The price path since the analysis, with the levels that mattered drawn across it.
 *
 * Deliberately a small SVG rather than a charting library: the point is to see whether price went
 * to the target or the stop, not to trade from it.
 */
function PricePathChart({
  path,
  predictions,
  tier,
}: {
  path: PricePath;
  predictions: Prediction[];
  tier: Analysis["data_tier"];
}) {
  const setup =
    predictions.find((prediction) => prediction.kind === "trade_setup") ?? predictions[0];
  const levels = [
    { label: "Entry", value: setup?.entry_price ?? null, tone: "stroke-foreground" },
    { label: "Stop", value: setup?.stop_price ?? null, tone: "stroke-rose-500" },
    { label: "Target", value: setup?.target_price_1 ?? null, tone: "stroke-emerald-500" },
  ].filter(
    (level): level is { label: string; value: number; tone: string } => level.value !== null,
  );

  const closes = path.bars.map((bar) => bar.close);
  const candidates = [
    ...closes,
    ...path.bars.map((bar) => bar.high),
    ...path.bars.map((bar) => bar.low),
    ...levels.map((level) => level.value),
  ];
  const min = Math.min(...candidates);
  const max = Math.max(...candidates);
  const span = max - min || 1;

  const width = 100;
  const height = 40;
  const x = (index: number) => (index / Math.max(1, path.bars.length - 1)) * width;
  const y = (price: number) => height - ((price - min) / span) * height;
  const line = path.bars.map((bar, index) => `${x(index)},${y(bar.close)}`).join(" ");

  return (
    <figure className="grid gap-2">
      <svg
        viewBox={`0 0 ${width} ${height}`}
        preserveAspectRatio="none"
        className="h-40 w-full rounded-md border bg-muted/20"
        role="img"
        aria-label={`Closing prices for the ${path.bars.length} sessions after the analysis, from ${formatPrice(
          closes[0],
          tier,
        )} to ${formatPrice(closes[closes.length - 1], tier)}.`}
      >
        {levels.map((level) => (
          <line
            key={level.label}
            x1="0"
            x2={width}
            y1={y(level.value)}
            y2={y(level.value)}
            className={cn(level.tone, "opacity-70")}
            strokeWidth="0.3"
            strokeDasharray="1.5 1.5"
            vectorEffect="non-scaling-stroke"
          />
        ))}
        <polyline
          points={line}
          fill="none"
          className="stroke-sky-500"
          strokeWidth="1"
          vectorEffect="non-scaling-stroke"
        />
      </svg>
      <figcaption className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
        {levels.map((level) => (
          <span key={level.label}>
            {level.label} {formatPrice(level.value, tier)}
          </span>
        ))}
        <span className="ml-auto">{path.bars.length} sessions since the analysis</span>
      </figcaption>
    </figure>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="mt-0.5 font-medium tabular-nums">{children}</dd>
    </div>
  );
}
