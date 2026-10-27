"use client";

import { Database, Tag } from "lucide-react";
import Link from "next/link";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import type { Analysis } from "@/lib/api/types";

import { LinkSymbolForm } from "./link-symbol-form";

/** Below this the engine cannot carry a numeric analysis — it mirrors MIN_BARS_TIER_A. */
const MIN_BARS = 60;

/**
 * Why this report has no numbers, and the one thing that would fix it.
 *
 * A Tier C report is a correct, deliberate refusal: without verified price data the app will not
 * invent an entry or a stop. But the report said only "WAIT (limited)", which reads like the app
 * had an opinion rather than a missing input — a user can lose an afternoon re-uploading
 * screenshots at it. The cause is always one of two things, and both have a specific fix, so the
 * report names the cause and links to the page that resolves it.
 */
export function DataLimitNotice({ analysis }: { analysis: Analysis }) {
  if (analysis.data_tier !== "vision_only") return null;

  const symbol = analysis.stock?.symbol ?? null;
  const bars = analysis.price_bar_count;

  // No symbol on the upload: nothing can be looked up, so this is the first thing to fix — and it
  // is fixable right here, without re-uploading anything.
  if (analysis.stock === null) {
    return (
      <Alert>
        <Tag aria-hidden />
        <AlertTitle>This chart isn&apos;t linked to a symbol</AlertTitle>
        <AlertDescription className="grid gap-3">
          <p>
            Without a ticker the app can&apos;t find price data, so it can only describe what the
            screenshot shows. Name the symbol and it will run again properly — the chart is already
            stored, so there is nothing to upload twice.
          </p>
          <LinkSymbolForm chartUploadId={analysis.chart_upload_id} />
        </AlertDescription>
      </Alert>
    );
  }

  return (
    <Notice
      title={
        bars === 0 || bars === null
          ? `No price data for ${symbol ?? "this symbol"} yet`
          : `Only ${bars} bars for ${symbol ?? "this symbol"} — ${MIN_BARS} are needed`
      }
      action={{ href: "/market-data", label: "Import price data", icon: <Database aria-hidden /> }}
    >
      A numeric plan needs at least {MIN_BARS} verified {analysis.timeframe.toLowerCase()} bars.
      Rather than estimate an entry and a stop from the picture, this report is limited to a
      qualitative read. Import an OHLCV CSV, then use <strong>Run it again</strong> above.
    </Notice>
  );
}

function Notice({
  title,
  action,
  children,
}: {
  title: string;
  action: { href: string; label: string; icon: React.ReactNode };
  children: React.ReactNode;
}) {
  return (
    <Alert>
      {action.icon}
      <AlertTitle>{title}</AlertTitle>
      <AlertDescription className="grid gap-3">
        <p>{children}</p>
        <Button asChild size="sm" variant="outline" className="w-fit">
          <Link href={action.href}>{action.label}</Link>
        </Button>
      </AlertDescription>
    </Alert>
  );
}
