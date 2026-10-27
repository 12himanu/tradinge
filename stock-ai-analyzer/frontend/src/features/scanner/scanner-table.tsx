"use client";

import Link from "next/link";
import { useState } from "react";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
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
import { BIAS_GLYPH, BIAS_TONE, DECISION_LABEL, DECISION_TONE } from "@/features/analysis/display";
import { cn } from "@/lib/utils";
import type { MarketScanSummary } from "@/lib/api/types";

import type { ScannerFilters } from "./api";
import { price, titleCase, ZONE_LABEL, ZONE_TONE } from "./display";
import { useMarketScan } from "./queries";
import { ScanDetail } from "./scan-detail";

const ANY = "any";

/**
 * Every stock the engine read last night, ranked, with the detail one click away.
 *
 * The table answers "where is something worth looking at"; the drawer answers "why". Scores rank
 * and never forecast — the note under the summary says so once so the columns do not have to.
 */
export function ScannerTable() {
  const [filters, setFilters] = useState<ScannerFilters>({ per_page: 50, sort: "score" });
  const [selected, setSelected] = useState<{ id: number; symbol: string | null } | null>(null);
  const scan = useMarketScan(filters);

  function setFilter(key: keyof ScannerFilters, value: string | boolean | number | undefined) {
    setFilters((current) => {
      const next: ScannerFilters = { ...current, page: undefined };
      if (value === ANY || value === "" || value === false || value === undefined) {
        delete next[key];
      } else {
        Object.assign(next, { [key]: value });
      }
      return next;
    });
  }

  const rows = scan.data?.data ?? [];
  const meta = scan.data?.meta;
  const summary = meta?.summary;

  return (
    <div className="grid gap-4">
      {summary ? <SummaryBar summary={summary} scannedOn={meta?.scanned_on ?? null} /> : null}

      {meta?.score_note ? (
        <p className="text-xs text-muted-foreground">{meta.score_note}</p>
      ) : null}

      <div className="flex flex-wrap items-center gap-2">
        <Input
          className="w-52"
          placeholder="Symbol or name"
          aria-label="Search by symbol or name"
          defaultValue={filters.q ?? ""}
          onChange={(event) => setFilter("q", event.target.value.trim())}
        />

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

        <Select
          value={filters.range_zone ?? ANY}
          onValueChange={(value) => setFilter("range_zone", value)}
        >
          <SelectTrigger className="w-40" aria-label="Filter by range location">
            <SelectValue placeholder="Any range zone" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ANY}>Any range zone</SelectItem>
            <SelectItem value="discount">Discount</SelectItem>
            <SelectItem value="equilibrium">Equilibrium</SelectItem>
            <SelectItem value="premium">Premium</SelectItem>
          </SelectContent>
        </Select>

        <Select value={filters.sort ?? "score"} onValueChange={(value) => setFilter("sort", value)}>
          <SelectTrigger className="w-44" aria-label="Sort">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="score">Highest score</SelectItem>
            <SelectItem value="score_asc">Lowest score</SelectItem>
            <SelectItem value="opportunities">Most opportunities</SelectItem>
            <SelectItem value="traps">Most traps</SelectItem>
            <SelectItem value="symbol">Symbol</SelectItem>
          </SelectContent>
        </Select>

        <Toggle
          label="Has opportunities"
          active={filters.opportunities === true}
          onClick={() => setFilter("opportunities", filters.opportunities ? undefined : true)}
        />
        <Toggle
          label="Has traps"
          active={filters.traps === true}
          onClick={() => setFilter("traps", filters.traps ? undefined : true)}
        />
        <Toggle
          label="Viable plan"
          active={filters.viable === true}
          onClick={() => setFilter("viable", filters.viable ? undefined : true)}
        />
      </div>

      {scan.isError ? (
        <Alert variant="destructive">
          <AlertTitle>Could not load the scan</AlertTitle>
          <AlertDescription>{scan.error.message}</AlertDescription>
        </Alert>
      ) : null}

      {scan.isPending ? (
        <div className="grid gap-2">
          {Array.from({ length: 8 }).map((_, index) => (
            <Skeleton key={index} className="h-10 w-full" />
          ))}
        </div>
      ) : null}

      {!scan.isPending && rows.length === 0 ? (
        <Alert>
          <AlertTitle>Nothing matches</AlertTitle>
          <AlertDescription>
            {summary && summary.scanned === 0
              ? "No scan has run yet. Import price data, then run `php artisan market:scan`."
              : "No stock in the latest scan matches these filters."}
          </AlertDescription>
        </Alert>
      ) : null}

      {rows.length > 0 ? (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Symbol</TableHead>
                <TableHead className="text-right">Price</TableHead>
                <TableHead>Bias</TableHead>
                <TableHead className="text-right">Score</TableHead>
                <TableHead>Range</TableHead>
                <TableHead className="text-right">Traps</TableHead>
                <TableHead className="text-right">Opps</TableHead>
                <TableHead>Decision</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((row) => (
                <TableRow
                  key={row.id}
                  tabIndex={0}
                  role="button"
                  aria-label={`Open ${row.stock.symbol ?? "stock"} detail`}
                  className="cursor-pointer"
                  onClick={() =>
                    row.stock.id !== null &&
                    setSelected({ id: row.stock.id, symbol: row.stock.symbol })
                  }
                  onKeyDown={(event) => {
                    if ((event.key === "Enter" || event.key === " ") && row.stock.id !== null) {
                      event.preventDefault();
                      setSelected({ id: row.stock.id, symbol: row.stock.symbol });
                    }
                  }}
                >
                  <TableCell>
                    <Link
                      href={`/stocks/${row.stock.symbol}`}
                      className="font-medium underline-offset-4 hover:underline"
                      // The row opens the drawer; the symbol is the way to the full page.
                      onClick={(event) => event.stopPropagation()}
                    >
                      {row.stock.symbol}
                    </Link>
                    <div className="max-w-48 truncate text-xs text-muted-foreground">
                      {row.stock.name}
                    </div>
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{price(row.last_price)}</TableCell>
                  <TableCell>
                    {row.bias ? (
                      <Badge variant="outline" className={cn("gap-1", BIAS_TONE[row.bias])}>
                        <span aria-hidden>{BIAS_GLYPH[row.bias]}</span>
                        {titleCase(row.bias)}
                      </Badge>
                    ) : (
                      "—"
                    )}
                  </TableCell>
                  <TableCell className="text-right font-medium tabular-nums">
                    {row.setup_score ?? "—"}
                  </TableCell>
                  <TableCell>
                    {row.range_zone ? (
                      <Badge variant="outline" className={ZONE_TONE[row.range_zone]}>
                        {ZONE_LABEL[row.range_zone]}
                      </Badge>
                    ) : (
                      "—"
                    )}
                  </TableCell>
                  <TableCell className="text-right tabular-nums">
                    {row.trap_count > 0 ? row.trap_count : <span className="text-muted-foreground">—</span>}
                  </TableCell>
                  <TableCell className="text-right tabular-nums">
                    {row.opportunity_count > 0 ? (
                      row.opportunity_count
                    ) : (
                      <span className="text-muted-foreground">—</span>
                    )}
                  </TableCell>
                  <TableCell>
                    {row.decision ? (
                      <Badge variant="outline" className={DECISION_TONE[row.decision]}>
                        {DECISION_LABEL[row.decision]}
                      </Badge>
                    ) : (
                      "—"
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      ) : null}

      {meta && meta.last_page > 1 ? (
        <div className="flex items-center justify-between text-sm">
          <span className="text-muted-foreground">
            Page {meta.current_page} of {meta.last_page} · {meta.total} stocks
          </span>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={meta.current_page <= 1}
              onClick={() => setFilters((c) => ({ ...c, page: meta.current_page - 1 }))}
            >
              Previous
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={meta.current_page >= meta.last_page}
              onClick={() => setFilters((c) => ({ ...c, page: meta.current_page + 1 }))}
            >
              Next
            </Button>
          </div>
        </div>
      ) : null}

      <ScanDetail
        stockId={selected?.id ?? null}
        symbol={selected?.symbol ?? null}
        onClose={() => setSelected(null)}
      />
    </div>
  );
}

function SummaryBar({
  summary,
  scannedOn,
}: {
  summary: MarketScanSummary;
  scannedOn: string | null;
}) {
  const tiles: Array<{ label: string; value: string }> = [
    { label: "Stocks read", value: summary.scanned.toLocaleString() },
    { label: "Bullish", value: summary.bullish.toLocaleString() },
    { label: "Bearish", value: summary.bearish.toLocaleString() },
    { label: "With traps", value: summary.with_traps.toLocaleString() },
    { label: "With openings", value: summary.with_opportunities.toLocaleString() },
    { label: "Viable plans", value: summary.viable_plans.toLocaleString() },
  ];

  return (
    <div className="grid gap-2">
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
        {tiles.map((tile) => (
          <div key={tile.label} className="rounded-md border px-3 py-2">
            <div className="text-xs text-muted-foreground">{tile.label}</div>
            <div className="text-lg font-semibold tabular-nums">{tile.value}</div>
          </div>
        ))}
      </div>
      <p className="text-xs text-muted-foreground">
        Session of {scannedOn ?? "—"}
        {summary.unreadable > 0
          ? ` · ${summary.unreadable} stocks had too little history to read`
          : ""}
      </p>
    </div>
  );
}

function Toggle({
  label,
  active,
  onClick,
}: {
  label: string;
  active: boolean;
  onClick: () => void;
}) {
  return (
    <Button
      type="button"
      variant={active ? "default" : "outline"}
      size="sm"
      aria-pressed={active}
      onClick={onClick}
    >
      {label}
    </Button>
  );
}
