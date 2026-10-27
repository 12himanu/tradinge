"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { BIAS_GLYPH, BIAS_TONE, DECISION_LABEL, DECISION_TONE } from "@/features/analysis/display";
import { price, titleCase, ZONE_LABEL, ZONE_TONE } from "@/features/scanner/display";
import { useStockScan } from "@/features/scanner/queries";
import { ScanReading } from "@/features/scanner/scan-detail";
import { cn } from "@/lib/utils";
import type { MarketScan, Stock } from "@/lib/api/types";

import { PriceTable } from "./price-table";
import { getStockBySymbol } from "./stock-api";

/**
 * One stock, in full: the engine's current read and the bars behind it.
 *
 * The summary at the top is the whole point — a person should be able to glance at it and know
 * where they stand without reading the sections below. Everything in it is a fact the pipeline
 * computed; nothing is a forecast.
 */
export function StockView({ symbol }: { symbol: string }) {
  const stock = useQuery({
    queryKey: ["stock", "by-symbol", symbol] as const,
    queryFn: ({ signal }) => getStockBySymbol(symbol, signal),
  });

  const scan = useStockScan(stock.data?.id ?? null);

  if (stock.isPending) {
    return (
      <div className="grid gap-3">
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-28 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (stock.isError) {
    return (
      <Alert variant="destructive">
        <AlertTitle>No such symbol</AlertTitle>
        <AlertDescription className="grid gap-3">
          <p>
            Nothing is listed as <strong>{symbol}</strong>. If it is a real NEPSE ticker, import the
            symbol list first.
          </p>
          <div>
            <Button asChild variant="outline" size="sm">
              <Link href="/scanner">Back to the scanner</Link>
            </Button>
          </div>
        </AlertDescription>
      </Alert>
    );
  }

  const data = stock.data;

  return (
    <div className="grid gap-6">
      <header className="grid gap-1">
        <div className="flex flex-wrap items-baseline gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">{data.symbol}</h1>
          <span className="text-muted-foreground">{data.name}</span>
        </div>
        <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
          {data.sector ? <span>{data.sector}</span> : null}
          <span>·</span>
          <span>{titleCase(data.instrument_type)}</span>
        </div>
      </header>

      {scan.isPending ? <Skeleton className="h-28 w-full" /> : null}
      {scan.data ? <Summary scan={scan.data} stock={data} /> : null}
      {scan.isError ? (
        <Alert>
          <AlertTitle>Not scanned yet</AlertTitle>
          <AlertDescription>
            The engine has not read this stock. It needs at least 60 daily bars; run a market scan
            once the price history is in.
          </AlertDescription>
        </Alert>
      ) : null}

      <Tabs defaultValue="analysis">
        <TabsList>
          <TabsTrigger value="analysis">Analysis</TabsTrigger>
          <TabsTrigger value="prices">Price history</TabsTrigger>
        </TabsList>

        <TabsContent value="analysis" className="mt-4">
          {scan.data ? (
            <ScanReading scan={scan.data} />
          ) : (
            <p className="text-sm text-muted-foreground">
              No reading yet — the analysis appears once this stock has been scanned.
            </p>
          )}
        </TabsContent>

        <TabsContent value="prices" className="mt-4">
          <PriceTable stockId={data.id} symbol={data.symbol} />
        </TabsContent>
      </Tabs>
    </div>
  );
}

/**
 * The glance view: where it stands, what to do, and the two counts that say why.
 */
function Summary({ scan, stock }: { scan: MarketScan; stock: Stock }) {
  return (
    <section className="grid gap-3 rounded-md border p-4">
      <div className="flex flex-wrap items-center gap-2">
        {scan.decision ? (
          <Badge variant="outline" className={cn("text-sm", DECISION_TONE[scan.decision])}>
            {DECISION_LABEL[scan.decision]}
          </Badge>
        ) : null}
        {scan.bias ? (
          <Badge variant="outline" className={cn("gap-1", BIAS_TONE[scan.bias])}>
            <span aria-hidden>{BIAS_GLYPH[scan.bias]}</span>
            {titleCase(scan.bias)}
          </Badge>
        ) : null}
        {scan.range_zone ? (
          <Badge variant="outline" className={ZONE_TONE[scan.range_zone]}>
            {ZONE_LABEL[scan.range_zone]}
          </Badge>
        ) : null}
        {scan.has_viable_plan ? (
          <Badge variant="outline" className="border-emerald-600/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300">
            Plan clears the gates
          </Badge>
        ) : null}
      </div>

      {scan.decision_rationale ? (
        <p className="text-sm">{scan.decision_rationale}</p>
      ) : null}

      <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-5">
        <Stat label="Last price" value={price(scan.last_price)} />
        <Stat label="Setup score" value={scan.setup_score === null ? "—" : `${scan.setup_score}/100`} />
        <Stat label="Traps" value={String(scan.trap_count)} />
        <Stat label="Openings" value={String(scan.opportunity_count)} />
        <Stat label="Structure" value={titleCase(scan.structure)} />
      </dl>

      <p className="text-xs text-muted-foreground">
        Read on {scan.scanned_on} from {scan.bar_count.toLocaleString()} stored bars of{" "}
        {stock.symbol}. The score ranks this setup against others; it is not a probability, and this
        reading files no prediction.
      </p>
    </section>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="font-medium tabular-nums">{value}</dd>
    </div>
  );
}
