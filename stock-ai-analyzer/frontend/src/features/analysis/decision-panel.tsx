"use client";

import { Info } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { cn } from "@/lib/utils";

import {
  BIAS_GLYPH,
  BIAS_TONE,
  DECISION_LABEL,
  DECISION_TONE,
  formatPrice,
  titleCase,
} from "./display";
import { EvidenceLink } from "./evidence-link";
import type { Analysis } from "@/lib/api/types";

/**
 * The answer, above the fold (P5-05).
 *
 * The score is shown with the word "uncalibrated" until 30 resolved outcomes in its score band
 * turn it into a real hit rate (P6-11). A number that looks like a probability but isn't would be
 * the single most misleading thing on this page, so the badge stays honest about which one it is.
 */
export function DecisionPanel({ analysis }: { analysis: Analysis }) {
  const decision = analysis.report?.decision;
  const action = analysis.decision;
  const score = analysis.setup_score;
  const bias = analysis.bias ?? "neutral";
  const agreement = analysis.score?.evidence_agreement;

  return (
    <Card>
      <CardContent className="grid gap-5 md:grid-cols-[minmax(0,1fr)_auto] md:items-start">
        <div className="grid gap-4">
          <div className="flex flex-wrap items-center gap-3">
            <span
              className={cn(
                "rounded-lg border px-3 py-1.5 text-lg font-semibold tracking-tight",
                action ? DECISION_TONE[action] : "border-border text-muted-foreground",
              )}
            >
              {action ? DECISION_LABEL[action] : "No decision"}
            </span>
            {analysis.decision_limited ? (
              <Badge variant="outline" className="gap-1">
                <Info aria-hidden /> Limited
              </Badge>
            ) : null}
            <span className={cn("text-sm font-medium", BIAS_TONE[bias])}>
              <span aria-hidden>{BIAS_GLYPH[bias]}</span> {titleCase(bias)} bias
            </span>
          </div>

          {decision?.rationale ? (
            <p className="text-sm leading-relaxed text-muted-foreground">{decision.rationale}</p>
          ) : null}

          {decision?.conditions?.length ? (
            <div className="grid gap-1.5">
              <h3 className="text-sm font-medium">What would change it</h3>
              <ul className="grid list-disc gap-1 pl-5 text-sm text-muted-foreground">
                {decision.conditions.map((condition) => (
                  <li key={condition}>{condition}</li>
                ))}
              </ul>
            </div>
          ) : null}

          {decision?.invalidation !== null && decision?.invalidation !== undefined ? (
            <p className="text-sm">
              <span className="text-muted-foreground">Invalidated below </span>
              <span className="font-medium tabular-nums">
                {formatPrice(decision.invalidation, analysis.data_tier)}
              </span>
            </p>
          ) : null}
        </div>

        <div className="grid gap-2 justify-items-start md:justify-items-end md:text-right">
          {score === null ? (
            <p className="max-w-[22ch] text-sm text-muted-foreground">
              No setup score at this tier: too little verified data to weigh.
            </p>
          ) : (
            <>
              <div className="flex items-baseline gap-1">
                <span className="text-4xl font-semibold tabular-nums">{score}</span>
                <span className="text-muted-foreground">/ 100</span>
              </div>
              <Badge
                variant="outline"
                className="font-normal"
                title={
                  analysis.calibrated_probability === null
                    ? "Fewer than 30 resolved outcomes in this score band for this engine version, so the score is not yet a probability"
                    : "How often setups this engine scored in this band reached the first target before the stop"
                }
              >
                {analysis.calibrated_probability === null
                  ? "Uncalibrated"
                  : `${Math.round(analysis.calibrated_probability * 100)}% historical hit rate`}
              </Badge>
              {agreement !== undefined ? (
                <p className="text-xs text-muted-foreground">
                  {Math.round(agreement * 100)}% of the evidence weight agrees
                  <EvidenceLink
                    id={analysis.evidence.find((item) => item.key === "setup_score")?.id}
                  />
                </p>
              ) : null}
            </>
          )}
        </div>
      </CardContent>
    </Card>
  );
}
