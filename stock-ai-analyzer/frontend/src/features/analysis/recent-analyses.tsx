"use client";

import Link from "next/link";

import { Skeleton } from "@/components/ui/skeleton";
import { formatDateTime } from "@/lib/format";
import { cn } from "@/lib/utils";

import { BIAS_GLYPH, BIAS_TONE, DECISION_LABEL, DECISION_TONE, titleCase } from "./display";
import { useHistory } from "./queries";

/** The last few runs, for the dashboard (P5-19). */
export function RecentAnalyses({ limit = 5 }: { limit?: number }) {
  const history = useHistory({ per_page: limit });

  if (history.isPending) return <Skeleton className="h-32 w-full" />;
  if (history.isError) return null;

  const rows = history.data.data;

  if (rows.length === 0) {
    return (
      <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
        Nothing analysed yet. Upload a chart to get started.
      </p>
    );
  }

  return (
    <ul className="grid gap-2">
      {rows.map((row) => (
        <li key={row.id}>
          <Link
            href={`/analysis/${row.id}`}
            className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border px-3 py-2 text-sm transition-colors hover:bg-muted"
          >
            <span className="font-medium">{row.stock?.symbol ?? "Unresolved"}</span>
            {row.decision ? (
              <span
                className={cn(
                  "rounded border px-1.5 py-0.5 text-xs font-medium",
                  DECISION_TONE[row.decision],
                )}
              >
                {DECISION_LABEL[row.decision]}
              </span>
            ) : (
              <span className="text-xs text-muted-foreground">{titleCase(row.status)}</span>
            )}
            {row.bias ? (
              <span className={cn("text-xs", BIAS_TONE[row.bias])}>
                <span aria-hidden>{BIAS_GLYPH[row.bias]}</span> {row.setup_score ?? "—"}
              </span>
            ) : null}
            <span className="ml-auto text-xs text-muted-foreground">
              {row.created_at ? formatDateTime(row.created_at) : ""}
            </span>
          </Link>
        </li>
      ))}
    </ul>
  );
}
