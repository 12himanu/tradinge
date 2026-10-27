"use client";

import { CalendarClock, Database, Search } from "lucide-react";
import Link from "next/link";
import { useState } from "react";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { BIAS_GLYPH, BIAS_TONE, DECISION_LABEL, DECISION_TONE } from "@/features/analysis/display";
import { SymbolAutocomplete } from "@/features/market/symbol-autocomplete";
import { price, titleCase } from "@/features/scanner/display";
import { useStockScan } from "@/features/scanner/queries";
import { useMe } from "@/features/auth/queries";
import { formatDate } from "@/lib/format";
import type { MarketScan, ScanLevel, Stock } from "@/lib/api/types";
import { cn } from "@/lib/utils";

import { AnalysisBreakdown } from "./analysis-breakdown";
import { HorizonCard } from "./horizon-card";

/**
 * What tomorrow and the coming week have looked like on one stock, by kind of analysis (P5-16).
 *
 * The page people ask for is "tell me what it will do". This app cannot honestly answer that, and
 * the design decision — the same one behind every other number here — is to answer the question it
 * can: over this symbol's own history, how often the next day closed higher, how big an ordinary
 * move is, and what today's signals were worth the other times they fired. Then each kind of
 * analysis states its own read, separately, so a disagreement between momentum and trend is
 * visible instead of averaged away.
 *
 * It reads the nightly scan rather than running the engine: the numbers are measured on the whole
 * price history, and recomputing them per page view would cost a GPU slot to produce the same
 * answer the scanner already filed.
 */
export function ForecastView() {
  const me = useMe();
  const [symbol, setSymbol] = useState("");
  const [stock, setStock] = useState<Stock | null>(null);
  const scan = useStockScan(stock?.id ?? null);

  return (
    <div className="grid gap-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Which stock?</CardTitle>
          <CardDescription>
            Anything with price data and a scan behind it. The scanner runs after the close.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-2 sm:max-w-sm">
          <Label htmlFor="forecast-symbol">Symbol</Label>
          <SymbolAutocomplete
            id="forecast-symbol"
            value={symbol}
            onChange={(value) => {
              setSymbol(value);
              // A half-typed symbol is not a selection; clearing avoids showing NABIL's forecast
              // under the letters "NAB".
              setStock(null);
            }}
            onSelect={(selected) => {
              setSymbol(selected.symbol);
              setStock(selected);
            }}
            exchange={me.data?.default_exchange?.code}
            placeholder="For example UPPER"
          />
        </CardContent>
      </Card>

      {stock === null ? <EmptyState /> : null}
      {stock !== null && scan.isPending ? <LoadingForecast /> : null}
      {stock !== null && scan.isError ? (
        <NotScanned symbol={stock.symbol} message={scan.error.message} code={scan.error.code} />
      ) : null}
      {stock !== null && scan.data ? <Forecast scan={scan.data} /> : null}
    </div>
  );
}

function Forecast({ scan }: { scan: MarketScan }) {
  const outlook = scan.outlook ?? [];

  if (scan.error_code !== null) {
    return (
      <Alert>
        <CalendarClock aria-hidden />
        <AlertTitle>The engine could not read {scan.stock.symbol}</AlertTitle>
        <AlertDescription>
          {scan.error_message ?? "The last scan of this stock failed."} Import more price data and
          the next scan will pick it up.
        </AlertDescription>
      </Alert>
    );
  }

  return (
    <div className="grid gap-6">
      <ScanHeader scan={scan} />

      {outlook.length === 0 ? (
        <Alert>
          <Database aria-hidden />
          <AlertTitle>This reading predates the forecast</AlertTitle>
          <AlertDescription className="grid gap-3">
            <p>
              The scan for {scan.stock.symbol} was filed before the next-day and next-week
              distributions were measured. The next scan will include them.
            </p>
            <Button asChild size="sm" variant="outline" className="w-fit">
              <Link href="/market-data">Run a scan</Link>
            </Button>
          </AlertDescription>
        </Alert>
      ) : (
        <section aria-labelledby="horizons" className="grid gap-3">
          <div>
            <h2 id="horizons" className="text-lg font-semibold tracking-tight">
              What the next day and week have been worth
            </h2>
            <p className="text-sm text-muted-foreground">
              Measured on this stock&apos;s own history, not predicted. The percentages describe
              what happened before; nothing here says what happens next.
            </p>
          </div>
          <div className="grid gap-3 lg:grid-cols-2">
            {outlook.map((horizon) => (
              <HorizonCard key={horizon.bars} horizon={horizon} />
            ))}
          </div>
        </section>
      )}

      <AnalysisBreakdown factors={scan.factors} />

      <WhatWouldChangeIt scan={scan} />

      <p className="border-t pt-4 text-xs text-muted-foreground">
        A base rate is what happened the last time, on a sample this page prints so you can discount
        it. The setup score is a rank, not a probability, and none of it is investment advice: the
        stop and the position size are what keep a wrong read survivable.
      </p>
    </div>
  );
}

function ScanHeader({ scan }: { scan: MarketScan }) {
  const bias = scan.bias ?? "neutral";

  return (
    <header className="grid gap-3">
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <h2 className="text-2xl font-semibold tracking-tight">{scan.stock.symbol}</h2>
        <span className="text-muted-foreground">{scan.stock.name}</span>
        <span className="tabular-nums text-muted-foreground">{price(scan.last_price)}</span>
      </div>
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <span className={cn("font-medium", BIAS_TONE[bias])}>
          <span aria-hidden className="mr-1">
            {BIAS_GLYPH[bias]}
          </span>
          {titleCase(bias)}
        </span>
        {scan.decision ? (
          <Badge variant="outline" className={DECISION_TONE[scan.decision]}>
            {DECISION_LABEL[scan.decision]}
          </Badge>
        ) : null}
        {scan.setup_score !== null ? (
          <span className="text-muted-foreground" title="A rank among setups, never a probability">
            setup rank {scan.setup_score}/100
          </span>
        ) : null}
        <span className="text-muted-foreground">
          · {scan.timeframe} · {titleCase(scan.trading_style)}
        </span>
        <span className="text-muted-foreground">
          · scanned {formatDate(scan.scanned_on)} on {scan.bar_count.toLocaleString()} bars
        </span>
      </div>
    </header>
  );
}

/**
 * The levels either side of price, because over a day or a week they are what a move runs into.
 * A forecast that ignores the wall twenty paisa above is a forecast about nothing.
 */
function WhatWouldChangeIt({ scan }: { scan: MarketScan }) {
  const last = scan.last_price;
  const levels = scan.levels ?? [];
  const above = nearest(levels, last, "above");
  const below = nearest(levels, last, "below");

  if (scan.decision_rationale === null && above === null && below === null) return null;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">What it runs into first</CardTitle>
        <CardDescription>
          Over a day or a week, the nearest levels matter more than the trend does.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3">
        <dl className="grid gap-3 sm:grid-cols-2">
          <LevelStat term="Nearest above" level={above} last={last} />
          <LevelStat term="Nearest below" level={below} last={last} />
        </dl>
        {scan.decision_rationale ? (
          <p className="text-sm text-muted-foreground">
            The engine&apos;s decision: {scan.decision_rationale}
          </p>
        ) : null}
      </CardContent>
    </Card>
  );
}

function LevelStat({
  term,
  level,
  last,
}: {
  term: string;
  level: ScanLevel | null;
  last: number | null;
}) {
  const distance =
    level === null || last === null || last === 0
      ? null
      : ((level.price_low + level.price_high) / 2 / last - 1) * 100;

  return (
    <div className="grid gap-0.5">
      <dt className="text-xs text-muted-foreground">{term}</dt>
      <dd className="text-lg font-semibold tabular-nums tracking-tight">
        {level === null
          ? "—"
          : level.price_low === level.price_high
            ? price(level.price_low)
            : `${price(level.price_low)} – ${price(level.price_high)}`}
      </dd>
      <dd className="text-xs text-muted-foreground">
        {level === null
          ? "None within the scanned range"
          : `${titleCase(level.type)}${distance === null ? "" : `, ${distance > 0 ? "+" : ""}${distance.toFixed(1)}% away`}`}
      </dd>
    </div>
  );
}

function nearest(
  levels: ScanLevel[],
  last: number | null,
  side: "above" | "below",
): ScanLevel | null {
  if (last === null) return null;

  const candidates = levels.filter((level) =>
    side === "above" ? level.price_low > last : level.price_high < last,
  );

  return (
    candidates.sort((a, b) =>
      side === "above" ? a.price_low - b.price_low : b.price_high - a.price_high,
    )[0] ?? null
  );
}

function EmptyState() {
  return (
    <Card>
      <CardContent className="grid gap-3 py-10 text-center">
        <Search className="mx-auto size-6 text-muted-foreground" aria-hidden />
        <p className="font-medium">Pick a symbol to see its next day and next week</p>
        <p className="mx-auto max-w-xl text-sm text-muted-foreground">
          You get three things: how often the next day and the next week have closed higher on that
          stock, how big an ordinary move is, and what each kind of analysis — trend, momentum,
          structure, volume, patterns, levels — says right now, separately, so you can see where
          they disagree.
        </p>
      </CardContent>
    </Card>
  );
}

function NotScanned({ symbol, message, code }: { symbol: string; message: string; code?: string }) {
  const missing = code === "NOT_SCANNED";

  return (
    <Alert>
      <Database aria-hidden />
      <AlertTitle>{missing ? `${symbol} hasn't been scanned yet` : "Couldn't load it"}</AlertTitle>
      <AlertDescription className="grid gap-3">
        <p>
          {missing
            ? "The forecast is measured from the nightly scan, which needs at least 60 daily bars of price history."
            : message}
        </p>
        <Button asChild size="sm" variant="outline" className="w-fit">
          <Link href="/market-data">Import data and scan</Link>
        </Button>
      </AlertDescription>
    </Alert>
  );
}

function LoadingForecast() {
  return (
    <div className="grid gap-4" aria-busy="true" aria-label="Loading the forecast">
      <Skeleton className="h-10 w-64" />
      <div className="grid gap-3 lg:grid-cols-2">
        <Skeleton className="h-56 w-full" />
        <Skeleton className="h-56 w-full" />
      </div>
      <Skeleton className="h-64 w-full" />
    </div>
  );
}
