"use client";

import { useQuery } from "@tanstack/react-query";
import { useState } from "react";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
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
import { titleCase } from "@/features/analysis/display";
import type { ApiError } from "@/lib/api/client";
import type { PerformanceStats } from "@/lib/api/types";

import { getPerformance, type Grouping, type StatsFilters } from "./api";
import { RateWithInterval, RHistogram, RValue, SampleWarning, formatRate } from "./stat-primitives";

const GROUP_LABELS: Record<Grouping, string> = {
  overall: "No breakdown",
  pattern: "By pattern",
  style: "By trading style",
  decision: "By decision",
  score_bucket: "By score band",
  data_tier: "By data tier",
};

/**
 * How the app's own calls have turned out (P6-08).
 *
 * The page leads with the sample size, not the hit rate. Until roughly 30 resolved predictions
 * exist, every figure here is an anecdote, and it says so rather than looking authoritative.
 */
export function PerformanceView() {
  const [filters, setFilters] = useState<StatsFilters>({ group_by: "style" });
  const stats = useQuery<PerformanceStats, ApiError>({
    queryKey: ["stats", "performance", filters],
    queryFn: ({ signal }) => getPerformance(filters, signal),
    placeholderData: (previous) => previous,
  });

  if (stats.isError) {
    return (
      <Alert variant="destructive">
        <AlertTitle>Can&apos;t load performance</AlertTitle>
        <AlertDescription>{stats.error.message}</AlertDescription>
      </Alert>
    );
  }

  if (stats.isPending) return <Skeleton className="h-96 w-full" />;

  const { overall, groups, r_histogram: histogram } = stats.data;
  const nothingYet = overall.predictions === 0;

  return (
    <div className="grid gap-6">
      <div className="flex flex-wrap items-center gap-2">
        <Select
          value={filters.group_by ?? "style"}
          onValueChange={(value) => setFilters((f) => ({ ...f, group_by: value as Grouping }))}
        >
          <SelectTrigger className="w-48" aria-label="Break results down by">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {Object.entries(GROUP_LABELS).map(([value, label]) => (
              <SelectItem key={value} value={value}>
                {label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        {overall.engine_versions.length > 1 ? (
          <Select
            value={filters.engine_version ?? "all"}
            onValueChange={(value) =>
              setFilters((f) => ({ ...f, engine_version: value === "all" ? undefined : value }))
            }
          >
            <SelectTrigger className="w-64" aria-label="Filter by engine version">
              <SelectValue placeholder="All engine versions" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All engine versions</SelectItem>
              {overall.engine_versions.map((version) => (
                <SelectItem key={version} value={version}>
                  {version}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        ) : null}

        <SampleWarning sufficient={overall.sufficient_sample} sample={overall.resolved} />
      </div>

      {nothingYet ? (
        <div className="rounded-lg border border-dashed p-10 text-center">
          <p className="font-medium">No predictions to score yet</p>
          <p className="mx-auto mt-1 max-w-prose text-sm text-muted-foreground">
            Every Tier A analysis records a directional call and a trade setup. After the NEPSE
            close they are checked against real bars, and the results appear here — wins and losses
            alike.
          </p>
        </div>
      ) : (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Metric title="Hit rate" description="Targets reached before the stop">
              <RateWithInterval
                rate={overall.hit_rate}
                interval={overall.hit_rate_interval}
                sample={overall.resolved}
              />
            </Metric>
            <Metric title="Trigger rate" description="Setups that became positions">
              <span className="font-medium tabular-nums">{formatRate(overall.trigger_rate)}</span>
              <span className="block text-xs text-muted-foreground">
                {overall.triggered} of {overall.predictions}
              </span>
            </Metric>
            <Metric title="Expectancy" description="Mean R per resolved setup">
              <RValue value={overall.expectancy_r} />
              <span className="block text-xs text-muted-foreground">
                median {overall.median_r === null ? "—" : `${overall.median_r.toFixed(2)}R`}
              </span>
            </Metric>
            <Metric title="Profit factor" description="Gross win over gross loss">
              <span className="font-medium tabular-nums">
                {overall.profit_factor === null ? "—" : overall.profit_factor.toFixed(2)}
              </span>
              {overall.profit_factor === null ? (
                <span className="block text-xs text-muted-foreground">No losses yet</span>
              ) : null}
            </Metric>
          </div>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">Directional calls</CardTitle>
              <CardDescription>
                Separate from trade setups: was the bias right at the horizon?
              </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-wrap gap-8">
              <div>
                <p className="text-xs uppercase tracking-wide text-muted-foreground">Accuracy</p>
                <RateWithInterval
                  rate={overall.directional_accuracy.rate}
                  interval={overall.directional_accuracy.interval}
                  sample={overall.directional_accuracy.total}
                />
              </div>
              <div>
                <p className="text-xs uppercase tracking-wide text-muted-foreground">Brier score</p>
                <p className="font-medium tabular-nums">
                  {overall.brier_score === null ? "—" : overall.brier_score.toFixed(3)}
                </p>
                <p className="text-xs text-muted-foreground">
                  Lower is better; 0.25 is a coin flip
                </p>
              </div>
            </CardContent>
          </Card>

          {groups.length > 0 ? (
            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  {GROUP_LABELS[stats.data.group_by as Grouping]}
                </CardTitle>
                <CardDescription>
                  Ordered by sample size, because that is what makes a row worth reading.
                </CardDescription>
              </CardHeader>
              <CardContent className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Group</TableHead>
                      <TableHead>Hit rate</TableHead>
                      <TableHead className="text-right">Expectancy</TableHead>
                      <TableHead className="text-right">Profit factor</TableHead>
                      <TableHead className="text-right">Resolved</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {groups.map((group) => (
                      <TableRow key={group.group}>
                        <TableCell className="font-medium">
                          {titleCase(group.group ?? "")}
                        </TableCell>
                        <TableCell>
                          <RateWithInterval
                            rate={group.hit_rate}
                            interval={group.hit_rate_interval}
                            sample={group.resolved}
                          />
                        </TableCell>
                        <TableCell className="text-right">
                          <RValue value={group.expectancy_r} />
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                          {group.profit_factor === null ? "—" : group.profit_factor.toFixed(2)}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">{group.resolved}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </CardContent>
            </Card>
          ) : null}

          <Card>
            <CardHeader>
              <CardTitle className="text-base">R distribution</CardTitle>
              <CardDescription>
                Where the outcomes actually landed. Losses bigger than −1R mean a gap or a stop that
                was not honoured.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <RHistogram bins={histogram} />
              {overall.ambiguous_bars > 0 ? (
                <p className="mt-3 text-xs text-muted-foreground">
                  {overall.ambiguous_bars} trade{overall.ambiguous_bars === 1 ? "" : "s"} hit the
                  stop and the target inside one bar and{" "}
                  {overall.ambiguous_bars === 1 ? "was" : "were"} counted as losses.
                </p>
              ) : null}
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}

function Metric({
  title,
  description,
  children,
}: {
  title: string;
  description: string;
  children: React.ReactNode;
}) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">{title}</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="text-lg">{children}</div>
        <p className="mt-1 text-xs text-muted-foreground">{description}</p>
      </CardContent>
    </Card>
  );
}
