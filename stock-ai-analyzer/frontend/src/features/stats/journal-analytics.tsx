"use client";

import { useQuery } from "@tanstack/react-query";
import { AlertTriangle } from "lucide-react";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { formatPrice, titleCase } from "@/features/analysis/display";
import { cn } from "@/lib/utils";
import type { JournalAnalytics, JournalMetrics } from "@/lib/api/types";

import { getJournalAnalytics } from "./api";
import { RateWithInterval, RValue, SampleWarning } from "./stat-primitives";

/**
 * What your own record says (P6-09).
 *
 * The flags are counts, not psychology. "You closed 4 of 6 winners by hand before the target" is
 * a fact; why you did it is yours to work out.
 */
export function JournalAnalyticsPanel() {
  const analytics = useQuery<JournalAnalytics>({
    queryKey: ["stats", "journal"],
    queryFn: ({ signal }) => getJournalAnalytics(signal),
  });

  if (analytics.isPending) return <Skeleton className="h-64 w-full" />;
  if (analytics.isError || analytics.data.totals.trades === 0) return null;

  const {
    totals,
    by_strategy: byStrategy,
    by_tag: byTag,
    plan_adherence: adherence,
    flags,
  } = analytics.data;

  return (
    <div className="grid gap-4">
      <Card>
        <CardHeader className="flex-row items-start justify-between gap-3">
          <div>
            <CardTitle className="text-base">Your record</CardTitle>
            <CardDescription>
              Closed trades only — an open position is not a result.
            </CardDescription>
          </div>
          <SampleWarning sufficient={totals.sufficient_sample} sample={totals.trades} />
        </CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-4">
          <Metric label="Win rate">
            <RateWithInterval
              rate={totals.win_rate}
              interval={totals.win_rate_interval}
              sample={totals.trades}
            />
          </Metric>
          <Metric label="Expectancy">
            <RValue value={totals.expectancy_r} />
          </Metric>
          <Metric label="Profit factor">
            {totals.profit_factor === null ? "—" : totals.profit_factor.toFixed(2)}
          </Metric>
          <Metric label="Net P&amp;L">
            <span
              className={cn(
                (totals.net_pnl ?? 0) >= 0
                  ? "text-emerald-700 dark:text-emerald-300"
                  : "text-rose-700 dark:text-rose-300",
              )}
            >
              <span aria-hidden>{(totals.net_pnl ?? 0) >= 0 ? "▲" : "▼"}</span>{" "}
              {formatPrice(totals.net_pnl)}
            </span>
          </Metric>
        </CardContent>
      </Card>

      {flags.length > 0 ? (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Patterns in how you trade</CardTitle>
            <CardDescription>
              Counted from your own entries, with the denominator shown.
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-2">
            {flags.map((flag) => (
              <div
                key={flag.key}
                className={cn(
                  "flex items-start gap-2 rounded-md border p-3 text-sm",
                  flag.severity === "high" ? "border-amber-600/40 bg-amber-500/5" : "bg-muted/30",
                )}
              >
                <AlertTriangle aria-hidden className="mt-0.5 size-4 shrink-0 text-amber-600" />
                <p>{flag.text}</p>
              </div>
            ))}
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Following the plan</CardTitle>
          <CardDescription>
            The comparison that usually matters more than any indicator.
          </CardDescription>
        </CardHeader>
        <CardContent className="overflow-x-auto">
          <MetricsTable
            rows={[adherence.followed, adherence.deviated]}
            headers={["", "Win rate", "Expectancy", "Trades"]}
          />
        </CardContent>
      </Card>

      {byStrategy.length > 1 ? (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">By strategy</CardTitle>
          </CardHeader>
          <CardContent className="overflow-x-auto">
            <MetricsTable
              rows={byStrategy}
              headers={["Strategy", "Win rate", "Expectancy", "Trades"]}
            />
          </CardContent>
        </Card>
      ) : null}

      {byTag.length > 0 ? (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">By tag</CardTitle>
          </CardHeader>
          <CardContent className="overflow-x-auto">
            <MetricsTable rows={byTag} headers={["Tag", "Win rate", "Expectancy", "Trades"]} />
          </CardContent>
        </Card>
      ) : null}
    </div>
  );
}

function MetricsTable({ rows, headers }: { rows: JournalMetrics[]; headers: string[] }) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          {headers.map((header, index) => (
            <TableHead key={header} className={index > 1 ? "text-right" : undefined}>
              {header}
            </TableHead>
          ))}
        </TableRow>
      </TableHeader>
      <TableBody>
        {rows.map((row) => (
          <TableRow key={row.group}>
            <TableCell className="font-medium">{titleCase(row.group ?? "")}</TableCell>
            <TableCell>
              <RateWithInterval
                rate={row.win_rate}
                interval={row.win_rate_interval}
                sample={row.trades}
              />
            </TableCell>
            <TableCell className="text-right">
              <RValue value={row.expectancy_r} />
            </TableCell>
            <TableCell className="text-right tabular-nums">{row.trades}</TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}

function Metric({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
      <div className="mt-0.5 text-lg font-medium tabular-nums">{children}</div>
    </div>
  );
}
