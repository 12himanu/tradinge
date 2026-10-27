"use client";

import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { cn } from "@/lib/utils";

import { BIAS_GLYPH, BIAS_TONE, formatPrice, formatSigned, titleCase } from "./display";
import { EvidenceLink } from "./evidence-link";
import type { DataTier, FactorScore, Scenario } from "@/lib/api/types";

/**
 * Both sides of the trade, always (P5-07).
 *
 * The bearish scenario is shown with the same weight as the bullish one even when the bias is
 * bullish: a report that only argues one way is advocacy, not analysis.
 */
export function ScenarioCard({ scenario, tier }: { scenario: Scenario; tier: DataTier | null }) {
  const direction = scenario.name === "bullish" ? "bullish" : "bearish";

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between gap-2">
        <CardTitle className={cn("flex items-center gap-2 text-base", BIAS_TONE[direction])}>
          <span aria-hidden>{BIAS_GLYPH[direction]}</span>
          {titleCase(scenario.name)}
        </CardTitle>
        {scenario.primary ? <Badge variant="secondary">Primary</Badge> : null}
      </CardHeader>
      <CardContent className="grid gap-2 text-sm">
        <p>
          <span className="text-muted-foreground">If </span>
          {scenario.trigger.toLowerCase()}
          <span className="text-muted-foreground">, then targets </span>
          <span className="font-medium tabular-nums">
            {scenario.targets.map((target) => formatPrice(target, tier)).join(" and ")}
          </span>
          .
        </p>
        {scenario.invalidation !== null ? (
          <p className="text-muted-foreground">
            Invalid if price closes beyond{" "}
            <span className="font-medium tabular-nums text-foreground">
              {formatPrice(scenario.invalidation, tier)}
            </span>
            .
          </p>
        ) : null}
        {scenario.narrative ? <p className="text-muted-foreground">{scenario.narrative}</p> : null}
      </CardContent>
    </Card>
  );
}

/** The bull/bear ledger with each factor's weight (docs/04 §5.6). */
export function FactorList({ factors }: { factors: FactorScore[] | undefined }) {
  const all = factors ?? [];
  const available = all.filter((factor) => factor.available && factor.contribution !== 0);
  const bullish = available.filter((factor) => factor.contribution > 0);
  const bearish = available.filter((factor) => factor.contribution < 0);
  const dropped = all.filter((factor) => !factor.available);

  return (
    <div className="grid gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <FactorColumn title="Bullish" glyph="✓" tone="emerald" factors={bullish} />
        <FactorColumn title="Bearish" glyph="✗" tone="rose" factors={bearish} />
      </div>
      {dropped.length > 0 ? (
        <p className="text-xs text-muted-foreground">
          Dropped for lack of data, with their weight redistributed:{" "}
          {dropped.map((factor) => factor.label).join(", ")}.
        </p>
      ) : null}
    </div>
  );
}

function FactorColumn({
  title,
  glyph,
  tone,
  factors,
}: {
  title: string;
  glyph: string;
  tone: "emerald" | "rose";
  factors: FactorScore[];
}) {
  return (
    <section aria-label={`${title} factors`} className="grid gap-2">
      <h3 className="text-sm font-medium">{title}</h3>
      {factors.length === 0 ? (
        <p className="text-sm text-muted-foreground">None.</p>
      ) : (
        <ul className="grid gap-2">
          {factors.map((factor) => (
            <li key={factor.key} className="grid gap-0.5 text-sm">
              <div className="flex items-baseline gap-2">
                <span
                  aria-hidden
                  className={cn(
                    tone === "emerald"
                      ? "text-emerald-700 dark:text-emerald-300"
                      : "text-rose-700 dark:text-rose-300",
                  )}
                >
                  {glyph}
                </span>
                <span className="font-medium">{factor.label}</span>
                <span className="tabular-nums text-muted-foreground">
                  ({formatSigned(factor.contribution)})
                </span>
                <EvidenceLink id={factor.evidence_ids[0]} />
              </div>
              <p className="pl-6 text-xs text-muted-foreground">{factor.reason}</p>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
