"use client";

import { AlertTriangle } from "lucide-react";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { formatDate } from "@/lib/format";

import { formatDuration, formatPrice, TIER_EXPLANATION, TIER_LABEL, titleCase } from "./display";
import type { Analysis } from "@/lib/api/types";

/** What was analysed, how good the data was, and anything the engine wants to warn about (P5-04). */
export function ReportHeader({ analysis }: { analysis: Analysis }) {
  const tier = analysis.data_tier;

  return (
    <header className="grid gap-3">
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">
          {analysis.stock?.symbol ?? "Unresolved symbol"}
        </h1>
        {analysis.stock?.name ? (
          <span className="text-muted-foreground">{analysis.stock.name}</span>
        ) : null}
        <span className="text-muted-foreground">
          {analysis.timeframe} · {titleCase(analysis.trading_style)}
        </span>
      </div>

      <div className="flex flex-wrap items-center gap-2 text-sm">
        {tier ? (
          <Badge variant="outline" title={TIER_EXPLANATION[tier]} className="cursor-help">
            {TIER_LABEL[tier]}
          </Badge>
        ) : null}
        {analysis.data_quality !== null ? (
          <span className="text-muted-foreground" title="How much the score was scaled by data quality">
            quality {analysis.data_quality.toFixed(2)}
          </span>
        ) : null}
        {analysis.as_of ? (
          <span className="text-muted-foreground">as of {formatDate(analysis.as_of)}</span>
        ) : null}
        {analysis.last_price !== null ? (
          <span className="tabular-nums text-muted-foreground">
            last {formatPrice(analysis.last_price, tier)}
          </span>
        ) : null}
        {analysis.duration_ms !== null ? (
          <span className="text-muted-foreground">· ran in {formatDuration(analysis.duration_ms)}</span>
        ) : null}
      </div>

      {analysis.warnings.length > 0 ? (
        <Alert>
          <AlertTriangle />
          <AlertTitle>Worth knowing</AlertTitle>
          <AlertDescription>
            <ul className="grid list-disc gap-1 pl-5">
              {analysis.warnings.map((warning) => (
                <li key={warning}>{warning}</li>
              ))}
            </ul>
          </AlertDescription>
        </Alert>
      ) : null}

      {analysis.status === "partial" ? (
        <Alert>
          <AlertTriangle />
          <AlertTitle>Limited analysis</AlertTitle>
          <AlertDescription>
            {tier ? TIER_EXPLANATION[tier] : "Not everything could be computed for this chart."}
          </AlertDescription>
        </Alert>
      ) : null}
    </header>
  );
}
