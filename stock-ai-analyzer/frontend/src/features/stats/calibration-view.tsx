"use client";

import { useQuery } from "@tanstack/react-query";
import { useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import type { ApiError } from "@/lib/api/client";
import type { Calibration, CalibrationBucket } from "@/lib/api/types";
import { cn } from "@/lib/utils";

import { getCalibration } from "./api";
import { formatRate } from "./stat-primitives";

const SCOPES = [
  { value: "global", label: "All styles" },
  { value: "style:swing", label: "Swing only" },
  { value: "style:positional", label: "Positional only" },
  { value: "style:intraday", label: "Intraday only" },
  { value: "style:investing", label: "Investing only" },
];

/**
 * Is the setup score telling the truth? (P6-10)
 *
 * The reliability diagram is the one chart that can convict the scoring model. Every point above
 * the diagonal is a band the app was too pessimistic about; every point below is one it oversold.
 * Bands that have not reached the minimum sample are drawn hollow and publish nothing — the report
 * keeps saying "uncalibrated" for them, which is the honest answer.
 */
export function CalibrationView() {
  const [scope, setScope] = useState("global");
  const [engineVersion, setEngineVersion] = useState<string | undefined>(undefined);

  const calibration = useQuery<Calibration, ApiError>({
    queryKey: ["stats", "calibration", scope, engineVersion],
    queryFn: ({ signal }) => getCalibration({ scope, engine_version: engineVersion }, signal),
    placeholderData: (previous) => previous,
  });

  if (calibration.isError) {
    return (
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Calibration</CardTitle>
          <CardDescription>{calibration.error.message}</CardDescription>
        </CardHeader>
      </Card>
    );
  }

  if (calibration.isPending) return <Skeleton className="h-80 w-full" />;

  const data = calibration.data;
  const plotted = data.buckets.filter((bucket) => bucket.n > 0);

  return (
    <Card>
      <CardHeader className="gap-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <CardTitle className="text-base">Is the score honest?</CardTitle>
            <CardDescription>
              What each score band claimed, against how often it actually reached T1 before the
              stop. Never pooled across engine versions — a re-weighting changes what a 70 means.
            </CardDescription>
          </div>
          <Badge variant={data.active ? "default" : "outline"} className="shrink-0 font-normal">
            {data.active ? "Calibration on" : "Uncalibrated"}
          </Badge>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <Select value={scope} onValueChange={setScope}>
            <SelectTrigger className="w-44" aria-label="Calibration scope">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {SCOPES.map((option) => (
                <SelectItem key={option.value} value={option.value}>
                  {option.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          {data.engine_versions.length > 1 ? (
            <Select
              value={engineVersion ?? data.engine_version ?? ""}
              onValueChange={setEngineVersion}
            >
              <SelectTrigger className="w-64" aria-label="Engine version">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {data.engine_versions.map((version) => (
                  <SelectItem key={version} value={version}>
                    {version}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          ) : null}
        </div>
      </CardHeader>

      <CardContent className="grid gap-6">
        {data.sample_size === 0 ? (
          <p className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
            No resolved setups for this engine version yet. Calibration needs{" "}
            {data.min_bucket_sample} outcomes in a score band before it will publish a probability,
            and until then reports say &ldquo;uncalibrated&rdquo; rather than guessing.
          </p>
        ) : (
          <>
            <ReliabilityDiagram buckets={plotted} minSample={data.min_bucket_sample} />

            <dl className="grid gap-4 sm:grid-cols-3">
              <Figure
                term="Brier score"
                value={data.brier_score}
                digits={3}
                hint="Raw setup score as a forecast. Lower is better; 0.25 is a coin flip."
              />
              <Figure
                term="Brier, calibrated"
                value={data.brier_score_calibrated}
                digits={3}
                hint={
                  data.brier_score_calibrated !== null &&
                  data.brier_score !== null &&
                  data.brier_score_calibrated < data.brier_score
                    ? "Calibration is improving the forecast."
                    : "Not beating the raw score yet."
                }
              />
              <Figure
                term="Calibration error"
                value={data.calibration_error}
                digits={3}
                hint="Average gap between the claimed and the realised rate."
              />
            </dl>

            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Score band</TableHead>
                    <TableHead className="text-right">Claimed</TableHead>
                    <TableHead className="text-right">Actual</TableHead>
                    <TableHead>95% interval</TableHead>
                    <TableHead className="text-right">n</TableHead>
                    <TableHead className="text-right">Published</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {plotted.map((bucket) => (
                    <TableRow key={bucket.bucket}>
                      <TableCell className="font-medium tabular-nums">{bucket.bucket}</TableCell>
                      <TableCell className="text-right tabular-nums text-muted-foreground">
                        {formatRate(bucket.predicted)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatRate(bucket.observed)}
                      </TableCell>
                      <TableCell className="tabular-nums text-xs text-muted-foreground">
                        {formatRate(bucket.interval.low)}–{formatRate(bucket.interval.high)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {bucket.n}
                        {bucket.support > bucket.n ? (
                          <span
                            className="ml-1 text-xs text-muted-foreground"
                            title={`Pooled with neighbouring bands: ${bucket.support} outcomes back this estimate`}
                          >
                            ({bucket.support})
                          </span>
                        ) : null}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {bucket.calibrated === null ? (
                          <span
                            className="text-muted-foreground"
                            title={`Needs ${data.min_bucket_sample} outcomes`}
                          >
                            —
                          </span>
                        ) : (
                          <span className="font-medium">{formatRate(bucket.calibrated)}</span>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>

            <p className="text-xs text-muted-foreground">
              Fitted {data.method === "isotonic" ? "by isotonic regression" : "as raw bands"} over{" "}
              {data.sample_size} resolved setup{data.sample_size === 1 ? "" : "s"}
              {data.method === "isotonic"
                ? ", which pools bands that contradict the ordering so a higher score can never claim a lower rate."
                : `. Isotonic regression switches on at 200 outcomes; below that a fitted curve would be modelling noise.`}
            </p>
          </>
        )}
      </CardContent>
    </Card>
  );
}

/**
 * Predicted on x, observed on y, with the diagonal for perfect calibration. Drawn as plain SVG:
 * the point of this chart is that it is checkable, so nothing about it should be magic.
 */
function ReliabilityDiagram({
  buckets,
  minSample,
}: {
  buckets: CalibrationBucket[];
  minSample: number;
}) {
  const size = 100;
  const x = (value: number) => value * size;
  const y = (value: number) => size - value * size;
  const maxN = Math.max(...buckets.map((bucket) => bucket.n), 1);
  const qualified = buckets.filter((bucket) => bucket.qualified);
  const curve = qualified
    .map((bucket) => `${x(bucket.predicted)},${y(bucket.calibrated ?? bucket.observed)}`)
    .join(" ");

  return (
    <figure className="grid gap-2">
      <svg
        viewBox={`-6 -6 ${size + 12} ${size + 12}`}
        className="aspect-square w-full max-w-sm rounded-md border bg-muted/20"
        role="img"
        aria-label={describe(buckets, minSample)}
      >
        {/* Perfect calibration: claimed equals realised. */}
        <line
          x1={x(0)}
          y1={y(0)}
          x2={x(1)}
          y2={y(1)}
          className="stroke-muted-foreground/50"
          strokeWidth="0.5"
          strokeDasharray="2 2"
        />
        {[0.25, 0.5, 0.75].map((tick) => (
          <g key={tick} className="stroke-border">
            <line x1={x(tick)} y1={y(0)} x2={x(tick)} y2={y(1)} strokeWidth="0.25" />
            <line x1={x(0)} y1={y(tick)} x2={x(1)} y2={y(tick)} strokeWidth="0.25" />
          </g>
        ))}

        {curve.split(" ").length > 1 ? (
          <polyline
            points={curve}
            fill="none"
            className="stroke-sky-500"
            strokeWidth="1"
            strokeLinejoin="round"
          />
        ) : null}

        {buckets.map((bucket) => (
          <g key={bucket.bucket}>
            {/* The interval, so a wide band reads as the uncertainty it is. */}
            <line
              x1={x(bucket.predicted)}
              y1={y(bucket.interval.low)}
              x2={x(bucket.predicted)}
              y2={y(bucket.interval.high)}
              className="stroke-sky-500/40"
              strokeWidth="0.6"
            />
            <circle
              cx={x(bucket.predicted)}
              cy={y(bucket.observed)}
              r={1.5 + (bucket.n / maxN) * 2.5}
              className={cn(
                bucket.qualified
                  ? "fill-sky-500 stroke-sky-600"
                  : "fill-background stroke-muted-foreground",
              )}
              strokeWidth="0.6"
            >
              <title>
                {`${bucket.bucket}: claimed ${formatRate(bucket.predicted)}, actual ${formatRate(
                  bucket.observed,
                )} from ${bucket.n} outcomes`}
              </title>
            </circle>
          </g>
        ))}
      </svg>
      <figcaption className="max-w-sm text-xs text-muted-foreground">
        Claimed rate across, realised rate up; the dashed line is perfect calibration. Dot size is
        sample size, hollow dots are bands under {minSample} outcomes and publish nothing. Points
        below the line mean the score was overconfident.
      </figcaption>
    </figure>
  );
}

function Figure({
  term,
  value,
  digits,
  hint,
}: {
  term: string;
  value: number | null;
  digits: number;
  hint: string;
}) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{term}</dt>
      <dd className="font-medium tabular-nums">{value === null ? "—" : value.toFixed(digits)}</dd>
      <p className="text-xs text-muted-foreground">{hint}</p>
    </div>
  );
}

function describe(buckets: CalibrationBucket[], minSample: number): string {
  const total = buckets.reduce((sum, bucket) => sum + bucket.n, 0);
  const live = buckets.filter((bucket) => bucket.qualified).length;
  const overconfident = buckets.filter((bucket) => bucket.observed < bucket.predicted).length;

  return `Reliability diagram over ${total} resolved setups in ${buckets.length} score bands; ${live} band(s) have reached ${minSample} outcomes, and ${overconfident} band(s) performed worse than their score claimed.`;
}
