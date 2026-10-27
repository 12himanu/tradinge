"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { BIAS_GLYPH, BIAS_TONE, DECISION_LABEL, DECISION_TONE } from "@/features/analysis/display";
import { getHotlist } from "@/features/market/market-data-api";
import { cn } from "@/lib/utils";
import type { DecisionAction, MarketScan } from "@/lib/api/types";

import { price, titleCase, ZONE_LABEL, ZONE_TONE } from "./display";

/** The order a reader wants them: what to do, then what to leave alone. */
const GROUPS: Array<{ decision: DecisionAction; blurb: string }> = [
  { decision: "buy", blurb: "Cleared every risk gate at today's price." },
  { decision: "hold", blurb: "Already in it, and the reason still stands." },
  { decision: "sell", blurb: "The reason to be in it has gone." },
  { decision: "wait", blurb: "Good chart, wrong price. The entry is not there yet." },
  { decision: "avoid", blurb: "Nothing here worth the risk." },
];

/**
 * What to do today, if anything.
 *
 * The scanner answers "show me everything, ranked". This answers the question you actually open
 * the app with. Empty groups are shown rather than hidden, because "nothing to buy" is an answer
 * and a page that silently omits it looks broken.
 */
export function Hotlist({ perGroup = 5 }: { perGroup?: number }) {
  const hotlist = useQuery({
    queryKey: ["market-scan", "hotlist", perGroup] as const,
    queryFn: ({ signal }) => getHotlist(perGroup, signal),
  });

  if (hotlist.isPending) {
    return (
      <div className="grid gap-2">
        {Array.from({ length: 3 }).map((_, index) => (
          <Skeleton key={index} className="h-24 w-full" />
        ))}
      </div>
    );
  }

  if (hotlist.isError) {
    return (
      <Alert variant="destructive">
        <AlertTitle>Could not load the hotlist</AlertTitle>
        <AlertDescription>{hotlist.error.message}</AlertDescription>
      </Alert>
    );
  }

  const groups = hotlist.data?.data;
  const meta = hotlist.data?.meta;
  const anything = GROUPS.some(({ decision }) => (groups?.[decision]?.length ?? 0) > 0);

  if (!anything) {
    return (
      <Alert>
        <AlertTitle>No readings yet</AlertTitle>
        <AlertDescription>
          Nothing has been scanned. Sync the market data, then the scan runs on its own.
        </AlertDescription>
      </Alert>
    );
  }

  return (
    <div className="grid gap-4">
      {meta ? (
        <p className="text-xs text-muted-foreground">
          Session of {meta.scanned_on ?? "—"} · {meta.summary.scanned} stocks read ·{" "}
          {meta.summary.viable_plans} with a plan that clears the gates
        </p>
      ) : null}

      <div className="grid gap-3">
        {GROUPS.map(({ decision, blurb }) => (
          <Group
            key={decision}
            decision={decision}
            blurb={blurb}
            scans={groups?.[decision] ?? []}
          />
        ))}
      </div>

      {meta ? <p className="text-xs text-muted-foreground">{meta.score_note}</p> : null}
    </div>
  );
}

function Group({
  decision,
  blurb,
  scans,
}: {
  decision: DecisionAction;
  blurb: string;
  scans: MarketScan[];
}) {
  return (
    <section className="rounded-md border">
      <header className="flex flex-wrap items-baseline justify-between gap-2 border-b px-3 py-2">
        <div className="flex items-center gap-2">
          <Badge variant="outline" className={DECISION_TONE[decision]}>
            {DECISION_LABEL[decision]}
          </Badge>
          <span className="text-xs text-muted-foreground">{blurb}</span>
        </div>
        <span className="text-xs tabular-nums text-muted-foreground">{scans.length}</span>
      </header>

      {scans.length === 0 ? (
        <p className="px-3 py-3 text-xs text-muted-foreground">Nothing in this group today.</p>
      ) : (
        <ul className="divide-y">
          {scans.map((scan) => (
            <li key={scan.id}>
              <Link
                href={`/stocks/${scan.stock.symbol}`}
                className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 hover:bg-muted/50"
              >
                <span className="w-20 font-medium">{scan.stock.symbol}</span>
                <span className="w-20 text-right tabular-nums">{price(scan.last_price)}</span>

                {scan.bias ? (
                  <Badge variant="outline" className={cn("gap-1", BIAS_TONE[scan.bias])}>
                    <span aria-hidden>{BIAS_GLYPH[scan.bias]}</span>
                    {titleCase(scan.bias)}
                  </Badge>
                ) : null}

                <span className="tabular-nums text-sm">{scan.setup_score ?? "—"}</span>

                {scan.range_zone ? (
                  <Badge variant="outline" className={ZONE_TONE[scan.range_zone]}>
                    {ZONE_LABEL[scan.range_zone]}
                  </Badge>
                ) : null}

                {scan.trap_count > 0 ? (
                  <span className="text-xs text-amber-700 dark:text-amber-300">
                    {scan.trap_count} trap{scan.trap_count === 1 ? "" : "s"}
                  </span>
                ) : null}

                <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">
                  {scan.decision_rationale}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
