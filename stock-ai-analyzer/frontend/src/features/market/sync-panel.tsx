"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { RefreshCw, Radar } from "lucide-react";
import { toast } from "sonner";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { Skeleton } from "@/components/ui/skeleton";
import type { ApiError } from "@/lib/api/client";
import type { BatchProgress, MarketDataStatus } from "@/lib/api/types";

import { getMarketDataStatus, startScan, startSync } from "./market-data-api";

export const marketDataKeys = {
  status: ["market-data", "status"] as const,
};

/**
 * How often to ask while a batch is running. The scrape takes over an hour and moves one symbol
 * every two seconds, so a slower poll would still look live and a faster one would only add load.
 */
const POLL_MS = 4000;

/**
 * Run the scraper now, and watch it.
 *
 * The nightly schedule is the normal path — this is for the first import, a catch-up after the
 * machine was off, or impatience. The work happens on the queue, so closing the page does not
 * stop it and reopening picks the progress back up: the state lives in `job_batches`, not here.
 */
export function SyncPanel() {
  const client = useQueryClient();

  const status = useQuery({
    queryKey: marketDataKeys.status,
    queryFn: ({ signal }) => getMarketDataStatus(signal),
    // Only poll while something is actually moving.
    refetchInterval: (query) =>
      query.state.data?.sync.running || query.state.data?.scan.running ? POLL_MS : false,
  });

  const sync = useMutation({
    mutationFn: startSync,
    onSuccess: (data) => {
      client.setQueryData(marketDataKeys.status, data);
      toast.success("Sync queued", {
        description: "Fetching the latest bars. The market scan runs when it finishes.",
      });
    },
    onError: (error: ApiError) =>
      toast.error(
        error.code === "SYNC_IN_PROGRESS" ? "Already running" : "Could not start the sync",
        { description: error.message },
      ),
  });

  const scan = useMutation({
    mutationFn: startScan,
    onSuccess: (data) => {
      client.setQueryData(marketDataKeys.status, data);
      toast.success("Scan queued", { description: "Re-reading every stock with the engine." });
    },
    onError: (error: ApiError) =>
      toast.error("Could not start the scan", { description: error.message }),
  });

  const busy = status.data?.sync.running === true;
  const scanning = status.data?.scan.running === true;

  return (
    <Card>
      <CardHeader>
        <CardTitle>Market data</CardTitle>
        <CardDescription>
          Prices arrive on their own at 17:00 Kathmandu. Run it now to catch up, or after the
          machine has been off.
        </CardDescription>
      </CardHeader>

      <CardContent className="grid gap-4">
        {status.isPending ? <Skeleton className="h-16 w-full" /> : null}

        {status.isError ? (
          <Alert variant="destructive">
            <AlertTitle>Could not read the market-data status</AlertTitle>
            <AlertDescription>{status.error.message}</AlertDescription>
          </Alert>
        ) : null}

        {status.data ? <Coverage status={status.data} /> : null}

        {busy ? <Running label="Fetching bars" batch={status.data!.sync} /> : null}
        {scanning ? <Running label="Reading the market" batch={status.data!.scan} /> : null}

        <div className="flex flex-wrap gap-2">
          <Button onClick={() => sync.mutate()} disabled={busy || sync.isPending}>
            <RefreshCw aria-hidden className={busy ? "animate-spin" : undefined} />
            {busy ? "Syncing…" : "Sync prices now"}
          </Button>

          <Button
            variant="outline"
            onClick={() => scan.mutate()}
            disabled={busy || scanning || scan.isPending}
          >
            <Radar aria-hidden className={scanning ? "animate-spin" : undefined} />
            {scanning ? "Scanning…" : "Re-scan only"}
          </Button>
        </div>

        <p className="text-xs text-muted-foreground">
          The work runs on the queue. You can leave this page — it keeps going, and the progress is
          still here when you come back.
        </p>
      </CardContent>
    </Card>
  );
}

function Coverage({ status }: { status: MarketDataStatus }) {
  const { coverage } = status;
  const tiles = [
    { label: "Stocks with data", value: `${coverage.stocks_with_bars} / ${coverage.stocks_total}` },
    { label: "Price bars", value: coverage.bars.toLocaleString() },
    { label: "Latest bar", value: coverage.latest_bar ?? "—" },
    { label: "Stocks scanned", value: coverage.scanned.toLocaleString() },
  ];

  return (
    <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
      {tiles.map((tile) => (
        <div key={tile.label} className="rounded-md border px-3 py-2">
          <dt className="text-xs text-muted-foreground">{tile.label}</dt>
          <dd className="text-sm font-semibold tabular-nums">{tile.value}</dd>
        </div>
      ))}
    </dl>
  );
}

function Running({ label, batch }: { label: string; batch: BatchProgress }) {
  const percent = batch.progress === null ? 0 : Math.round(batch.progress * 100);
  const done = batch.done ?? batch.total - batch.pending;

  return (
    <div className="grid gap-1">
      <div className="flex items-baseline justify-between text-sm">
        <span>{label}</span>
        <span className="tabular-nums text-muted-foreground">
          {done} / {batch.total}
          {batch.failed > 0 ? ` · ${batch.failed} failed` : ""}
        </span>
      </div>
      <Progress value={percent} aria-label={`${label}: ${percent}% complete`} />
    </div>
  );
}
