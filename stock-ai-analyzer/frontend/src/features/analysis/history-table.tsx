"use client";

import Link from "next/link";
import { useState } from "react";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
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
import { formatDateTime } from "@/lib/format";
import { cn } from "@/lib/utils";

import { BIAS_GLYPH, BIAS_TONE, DECISION_LABEL, DECISION_TONE, TIER_LABEL, titleCase } from "./display";
import { useHistory } from "./queries";
import type { HistoryFilters } from "./api";
import type { DecisionAction } from "@/lib/api/types";

const ANY = "any";

const DECISIONS: DecisionAction[] = ["buy", "wait", "hold", "sell", "avoid"];

/** Every analysis you have run, filterable (P5-14). */
export function HistoryTable() {
  const [filters, setFilters] = useState<HistoryFilters>({ per_page: 20 });
  const history = useHistory(filters);

  function setFilter(key: keyof HistoryFilters, value: string) {
    setFilters((current) => {
      const next = { ...current, page: undefined };
      if (value === ANY) delete next[key];
      else Object.assign(next, { [key]: value });
      return next;
    });
  }

  const rows = history.data?.data ?? [];
  const meta = history.data?.meta;

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap gap-2">
        <Select value={filters.decision ?? ANY} onValueChange={(value) => setFilter("decision", value)}>
          <SelectTrigger className="w-40" aria-label="Filter by decision">
            <SelectValue placeholder="Any decision" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ANY}>Any decision</SelectItem>
            {DECISIONS.map((decision) => (
              <SelectItem key={decision} value={decision}>
                {DECISION_LABEL[decision]}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select value={filters.bias ?? ANY} onValueChange={(value) => setFilter("bias", value)}>
          <SelectTrigger className="w-36" aria-label="Filter by bias">
            <SelectValue placeholder="Any bias" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ANY}>Any bias</SelectItem>
            <SelectItem value="bullish">Bullish</SelectItem>
            <SelectItem value="bearish">Bearish</SelectItem>
            <SelectItem value="neutral">Neutral</SelectItem>
          </SelectContent>
        </Select>

        <Select value={filters.tier ?? ANY} onValueChange={(value) => setFilter("tier", value)}>
          <SelectTrigger className="w-48" aria-label="Filter by data tier">
            <SelectValue placeholder="Any data tier" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ANY}>Any data tier</SelectItem>
            <SelectItem value="ohlcv">{TIER_LABEL.ohlcv}</SelectItem>
            <SelectItem value="digitized">{TIER_LABEL.digitized}</SelectItem>
            <SelectItem value="vision_only">{TIER_LABEL.vision_only}</SelectItem>
          </SelectContent>
        </Select>

        {Object.keys(filters).length > 1 ? (
          <Button variant="ghost" size="sm" onClick={() => setFilters({ per_page: 20 })}>
            Clear filters
          </Button>
        ) : null}
      </div>

      {history.isError ? (
        <Alert variant="destructive">
          <AlertTitle>Can&apos;t load your history</AlertTitle>
          <AlertDescription>{history.error.message}</AlertDescription>
        </Alert>
      ) : history.isPending ? (
        <Skeleton className="h-48 w-full" />
      ) : rows.length === 0 ? (
        <div className="rounded-lg border border-dashed p-10 text-center">
          <p className="font-medium">No analyses yet</p>
          <p className="mt-1 text-sm text-muted-foreground">
            Upload a chart on the Analyze page and run it; every run shows up here.
          </p>
          <Button asChild className="mt-4">
            <Link href="/analyze">Analyze a chart</Link>
          </Button>
        </div>
      ) : (
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Symbol</TableHead>
                <TableHead>Decision</TableHead>
                <TableHead className="text-right">Score</TableHead>
                <TableHead>Bias</TableHead>
                <TableHead>Tier</TableHead>
                <TableHead>Run</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((row) => (
                <TableRow key={row.id}>
                  <TableCell>
                    <Link href={`/analysis/${row.id}`} className="font-medium hover:underline">
                      {row.stock?.symbol ?? "Unresolved"}
                    </Link>
                    <span className="block text-xs text-muted-foreground">
                      {row.timeframe} · {titleCase(row.trading_style)}
                    </span>
                  </TableCell>
                  <TableCell>
                    {row.decision ? (
                      <span
                        className={cn(
                          "rounded border px-2 py-0.5 text-xs font-medium",
                          DECISION_TONE[row.decision],
                        )}
                      >
                        {DECISION_LABEL[row.decision]}
                      </span>
                    ) : (
                      <Badge variant="outline">{titleCase(row.status)}</Badge>
                    )}
                  </TableCell>
                  <TableCell className="text-right tabular-nums">
                    {row.setup_score ?? "—"}
                  </TableCell>
                  <TableCell className={row.bias ? BIAS_TONE[row.bias] : undefined}>
                    {row.bias ? (
                      <>
                        <span aria-hidden>{BIAS_GLYPH[row.bias]}</span> {titleCase(row.bias)}
                      </>
                    ) : (
                      "—"
                    )}
                  </TableCell>
                  <TableCell className="text-xs text-muted-foreground">
                    {row.data_tier ? TIER_LABEL[row.data_tier].split(" · ")[0] : "—"}
                  </TableCell>
                  <TableCell className="text-xs text-muted-foreground">
                    {row.created_at ? formatDateTime(row.created_at) : "—"}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      {meta && meta.last_page > 1 ? (
        <div className="flex items-center justify-between text-sm">
          <span className="text-muted-foreground">
            Page {meta.current_page} of {meta.last_page} · {meta.total} analyses
          </span>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={meta.current_page <= 1}
              onClick={() => setFilters((f) => ({ ...f, page: meta.current_page - 1 }))}
            >
              Previous
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={meta.current_page >= meta.last_page}
              onClick={() => setFilters((f) => ({ ...f, page: meta.current_page + 1 }))}
            >
              Next
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
