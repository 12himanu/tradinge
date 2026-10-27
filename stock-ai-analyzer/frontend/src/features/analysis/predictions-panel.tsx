"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";

import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { apiFetch } from "@/lib/api/client";
import { formatDate } from "@/lib/format";
import type { Paginated, Prediction } from "@/lib/api/types";

import { formatPrice, titleCase } from "./display";

async function getPredictions(signal?: AbortSignal): Promise<Paginated<Prediction>> {
  return apiFetch<Paginated<Prediction>>("/predictions", { signal });
}

/**
 * Open predictions (P5-15).
 *
 * These are the app's falsifiable claims: each one has a trigger, a stop and a deadline, and
 * Phase 6 scores them against real bars whether they worked or not.
 */
export function PredictionsPanel({ limit }: { limit?: number }) {
  const predictions = useQuery({
    queryKey: ["predictions", "open"],
    queryFn: ({ signal }) => getPredictions(signal),
  });

  if (predictions.isPending) return <Skeleton className="h-32 w-full" />;
  if (predictions.isError) return null;

  const rows = limit ? predictions.data.data.slice(0, limit) : predictions.data.data;

  if (rows.length === 0) {
    return (
      <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
        No open predictions. Every Tier A analysis records one, then it is scored against real bars.
      </p>
    );
  }

  return (
    <div className="overflow-x-auto">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Kind</TableHead>
            <TableHead>Trigger</TableHead>
            <TableHead className="text-right">Stop</TableHead>
            <TableHead className="text-right">Target</TableHead>
            <TableHead>Expires</TableHead>
            <TableHead>Status</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((prediction) => (
            <TableRow key={prediction.id}>
              <TableCell>
                <Link href={`/analysis/${prediction.analysis_id}`} className="hover:underline">
                  {titleCase(prediction.kind)}
                </Link>
                <span className="block text-xs text-muted-foreground">
                  {prediction.timeframe} · {prediction.horizon_bars} bars
                </span>
              </TableCell>
              <TableCell className="tabular-nums">
                {prediction.trigger_price === null
                  ? titleCase(prediction.trigger_type)
                  : `${titleCase(prediction.trigger_type)} ${formatPrice(prediction.trigger_price)}`}
              </TableCell>
              <TableCell className="text-right tabular-nums">
                {formatPrice(prediction.stop_price)}
              </TableCell>
              <TableCell className="text-right tabular-nums">
                {formatPrice(prediction.target_price_1)}
              </TableCell>
              <TableCell className="text-sm text-muted-foreground">
                {formatDate(prediction.expires_at)}
              </TableCell>
              <TableCell>
                <Badge variant={prediction.status === "active" ? "default" : "outline"}>
                  {titleCase(prediction.status)}
                </Badge>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
