"use client";

import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { cn } from "@/lib/utils";

import { formatPercent, formatPrice, formatRatio, planTitle, titleCase } from "./display";
import { EvidenceLink } from "./evidence-link";
import type { DataTier, TradePlan } from "@/lib/api/types";

type Props = { plan: TradePlan; tier: DataTier | null; currency?: string };

/**
 * One trade plan (P5-06).
 *
 * A plan that fails the risk gates is shown, not hidden: "good chart, poor price" is useful
 * information, and the price that would make it viable is the actionable part.
 */
export function TradePlanCard({ plan, tier, currency = "NPR" }: Props) {
  return (
    <Card className={cn(!plan.viable && "border-dashed")}>
      <CardHeader className="flex-row items-center justify-between gap-3">
        <CardTitle className="text-base">{planTitle(plan)}</CardTitle>
        <div className="flex items-center gap-2">
          {plan.trigger_met ? <Badge variant="secondary">Trigger met</Badge> : null}
          <Badge variant={plan.viable ? "default" : "outline"}>
            {plan.viable ? "Viable" : "Not viable"}
          </Badge>
        </div>
      </CardHeader>

      <CardContent className="grid gap-4">
        <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
          <Field label="Entry">
            {formatPrice(plan.entry_price, tier, currency)}
            {plan.trigger_price !== null ? (
              <span className="block text-xs text-muted-foreground">
                on {titleCase(plan.trigger_type).toLowerCase()} {formatPrice(plan.trigger_price, tier, currency)}
              </span>
            ) : null}
          </Field>
          <Field label="Stop">
            {formatPrice(plan.stop_price, tier, currency)}
            <span className="block text-xs text-muted-foreground">{plan.stop_basis}</span>
          </Field>
          <Field label="Targets">
            {plan.targets.length === 0
              ? "—"
              : plan.targets.map((target) => formatPrice(target, tier, currency)).join(" · ")}
          </Field>
          <Field label="Risk / reward">
            {formatPercent(plan.risk_pct)} / {formatPercent(plan.reward_pct)}
          </Field>
          <Field label="R:R (net)">
            {formatRatio(plan.rr_net)}
            <span className="block text-xs text-muted-foreground">
              {formatRatio(plan.rr_gross)} before costs
            </span>
          </Field>
          <Field label="Size">
            {plan.quantity.toLocaleString()} shares
            <span className="block text-xs text-muted-foreground">
              {formatPrice(plan.position_value, null, currency)} · {formatPercent(plan.position_pct, 1)} of account
            </span>
          </Field>
        </dl>

        {!plan.viable && plan.reasons.length > 0 ? (
          <div className="rounded-md border border-dashed bg-muted/40 p-3 text-sm">
            <p className="font-medium">Why it isn&apos;t viable</p>
            <ul className="mt-1 grid list-disc gap-1 pl-5 text-muted-foreground">
              {plan.reasons.map((reason) => (
                <li key={reason}>{reason}</li>
              ))}
            </ul>
            {plan.viable_at_price !== null ? (
              <p className="mt-2">
                It clears the gates at an entry of{" "}
                <span className="font-medium tabular-nums">
                  {formatPrice(plan.viable_at_price, tier, currency)}
                </span>
                .
              </p>
            ) : null}
          </div>
        ) : null}

        <p className="text-xs text-muted-foreground">
          Sizing uses your account settings and the exchange fee schedule.
          <EvidenceLink id={plan.evidence_id} />
        </p>
      </CardContent>
    </Card>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="mt-0.5 font-medium tabular-nums">{children}</dd>
    </div>
  );
}
