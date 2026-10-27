"use client";

import { useQuery } from "@tanstack/react-query";
import { useState } from "react";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
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
import type { PriceBar } from "@/lib/api/types";

import { getPriceBars } from "./stock-api";

const SIZES = [60, 120, 250, 500] as const;

/**
 * The stored bars for one symbol: open, high, low, close, volume and turnover.
 *
 * Newest first, because the question is almost always "what happened lately". The API returns them
 * oldest-first for the engine's benefit, so they are reversed here rather than asking it to serve
 * two orders.
 */
export function PriceTable({ stockId, symbol }: { stockId: number; symbol: string }) {
  const [limit, setLimit] = useState<number>(120);

  const bars = useQuery({
    queryKey: ["prices", stockId, limit] as const,
    queryFn: ({ signal }) => getPriceBars(stockId, { timeframe: "1D", limit }, signal),
    placeholderData: (previous) => previous,
  });

  const rows = [...(bars.data?.data ?? [])].reverse();

  return (
    <div className="grid gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-muted-foreground">
          {bars.data?.meta ? `${bars.data.meta.count} daily bars` : "Daily bars"} for {symbol},
          newest first.
        </p>

        <Select value={String(limit)} onValueChange={(value) => setLimit(Number(value))}>
          <SelectTrigger className="w-36" aria-label="How many bars to show">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {SIZES.map((size) => (
              <SelectItem key={size} value={String(size)}>
                Last {size}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      {bars.isError ? (
        <Alert variant="destructive">
          <AlertTitle>Could not load the price history</AlertTitle>
          <AlertDescription>{bars.error.message}</AlertDescription>
        </Alert>
      ) : null}

      {bars.isPending ? (
        <div className="grid gap-2">
          {Array.from({ length: 8 }).map((_, index) => (
            <Skeleton key={index} className="h-8 w-full" />
          ))}
        </div>
      ) : null}

      {!bars.isPending && rows.length === 0 ? (
        <Alert>
          <AlertTitle>No bars stored</AlertTitle>
          <AlertDescription>
            This symbol has no price history yet. Sync the market data, or import a CSV.
          </AlertDescription>
        </Alert>
      ) : null}

      {rows.length > 0 ? (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Date</TableHead>
                <TableHead className="text-right">Open</TableHead>
                <TableHead className="text-right">High</TableHead>
                <TableHead className="text-right">Low</TableHead>
                <TableHead className="text-right">Close</TableHead>
                <TableHead className="text-right">Change</TableHead>
                <TableHead className="text-right">Volume</TableHead>
                <TableHead className="text-right">Turnover</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((bar, index) => (
                <Row key={bar.time} bar={bar} previous={rows[index + 1]} />
              ))}
            </TableBody>
          </Table>
        </div>
      ) : null}
    </div>
  );
}

function Row({ bar, previous }: { bar: PriceBar; previous: PriceBar | undefined }) {
  // Against the previous session's close, which is what a change column means to a trader.
  const change = previous ? ((bar.close - previous.close) / previous.close) * 100 : null;

  return (
    <TableRow>
      <TableCell className="whitespace-nowrap">{bar.time.slice(0, 10)}</TableCell>
      <TableCell className="text-right tabular-nums">{num(bar.open)}</TableCell>
      <TableCell className="text-right tabular-nums">{num(bar.high)}</TableCell>
      <TableCell className="text-right tabular-nums">{num(bar.low)}</TableCell>
      <TableCell className="text-right font-medium tabular-nums">{num(bar.close)}</TableCell>
      <TableCell
        className={`text-right tabular-nums ${
          change === null
            ? ""
            : change > 0
              ? "text-emerald-700 dark:text-emerald-300"
              : change < 0
                ? "text-rose-700 dark:text-rose-300"
                : ""
        }`}
      >
        {change === null ? "—" : `${change > 0 ? "+" : ""}${change.toFixed(2)}%`}
      </TableCell>
      <TableCell className="text-right tabular-nums text-muted-foreground">
        {bar.volume === null ? "—" : Math.round(bar.volume).toLocaleString()}
      </TableCell>
      <TableCell className="text-right tabular-nums text-muted-foreground">
        {bar.turnover === null ? "—" : Math.round(bar.turnover).toLocaleString()}
      </TableCell>
    </TableRow>
  );
}

function num(value: number): string {
  return value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
