"use client";

import { AlertTriangle } from "lucide-react";
import { useRouter } from "next/navigation";
import { useEffect } from "react";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useQueryClient } from "@tanstack/react-query";

import { AnalysisProgressView } from "./analysis-progress";
import { DataLimitNotice } from "./data-limit-notice";
import { DecisionPanel } from "./decision-panel";
import { explainError } from "./display";
import { analysisKeys, useAnalysis, useAnalysisProgress, useRequestAnalysis } from "./queries";
import { ReportActions } from "./report-actions";
import { ReportHeader } from "./report-header";
import { ReportTabs } from "./report-tabs";
import { FactorList, ScenarioCard } from "./scenarios-and-factors";
import { RecordFromPlan } from "@/features/journal/record-from-plan";
import { AiVsActual } from "@/features/stats/ai-vs-actual";

import { TradePlanCard } from "./trade-plan-card";

/**
 * One analysis, from queued to report.
 *
 * While it runs, the cheap status endpoint is polled and the stage checklist is shown; the report
 * itself is fetched once the run reaches a terminal state.
 */
export function AnalysisView({ id }: { id: string }) {
  const router = useRouter();
  const queryClient = useQueryClient();
  const progress = useAnalysisProgress(id);
  const terminal = progress.data?.terminal ?? false;
  const analysis = useAnalysis(id, terminal);
  const rerun = useRequestAnalysis();

  // The run just finished: pull the report in (the polling query only carries status).
  useEffect(() => {
    if (terminal) void queryClient.invalidateQueries({ queryKey: analysisKeys.detail(id) });
  }, [terminal, id, queryClient]);

  if (progress.isError) {
    return (
      <Alert variant="destructive">
        <AlertTriangle />
        <AlertTitle>Can&apos;t load this analysis</AlertTitle>
        <AlertDescription>{progress.error.message}</AlertDescription>
      </Alert>
    );
  }

  if (progress.isPending) return <LoadingReport />;

  if (!terminal || progress.data.status === "failed" || progress.data.status === "cancelled") {
    return (
      <AnalysisProgressView
        progress={progress.data}
        retrying={rerun.isPending}
        chartUploadId={analysis.data?.chart_upload_id}
        declaredSymbol={analysis.data?.stock?.symbol}
        onRetry={
          analysis.data
            ? () =>
                rerun.mutate(
                  {
                    chartId: analysis.data.chart_upload_id,
                    body: {
                      timeframe: analysis.data.timeframe,
                      trading_style: analysis.data.trading_style,
                      force: true,
                    },
                  },
                  { onSuccess: (fresh) => router.push(`/analysis/${fresh.id}`) },
                )
            : undefined
        }
      />
    );
  }

  if (analysis.isPending) return <LoadingReport />;

  if (analysis.isError) {
    return (
      <Alert variant="destructive">
        <AlertTriangle />
        <AlertTitle>Can&apos;t load the report</AlertTitle>
        <AlertDescription>{analysis.error.message}</AlertDescription>
      </Alert>
    );
  }

  const report = analysis.data;
  const scenarios = report.report?.scenarios ?? [];
  const plans = report.report?.trade_plans ?? [];

  if (report.status === "failed") {
    return (
      <Alert variant="destructive">
        <AlertTriangle />
        <AlertTitle>Analysis failed</AlertTitle>
        <AlertDescription>{explainError(report.error_code, report.error_message)}</AlertDescription>
      </Alert>
    );
  }

  return (
    <article className="grid gap-6">
      <ReportHeader analysis={report} />
      <ReportActions analysis={report} />
      <DataLimitNotice analysis={report} />
      <DecisionPanel analysis={report} />

      {plans.length > 0 ? (
        <section aria-labelledby="plans" className="grid gap-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 id="plans" className="text-lg font-semibold tracking-tight">
              Trade plans
            </h2>
            <RecordFromPlan
              analysis={report}
              plan={plans.find((plan) => plan.viable) ?? plans[0]}
            />
          </div>
          <div className="grid gap-3 lg:grid-cols-2">
            {plans.map((plan, index) => (
              <TradePlanCard
                key={`${plan.entry_type}-${index}`}
                plan={plan}
                tier={report.data_tier}
              />
            ))}
          </div>
        </section>
      ) : null}

      {scenarios.length > 0 ? (
        <section aria-labelledby="scenarios" className="grid gap-3">
          <h2 id="scenarios" className="text-lg font-semibold tracking-tight">
            Scenarios
          </h2>
          <div className="grid gap-3 lg:grid-cols-2">
            {scenarios.map((scenario) => (
              <ScenarioCard key={scenario.name} scenario={scenario} tier={report.data_tier} />
            ))}
          </div>
        </section>
      ) : null}

      {report.score && report.score.factors?.length ? (
        <Card>
          <CardHeader>
            <CardTitle className="text-lg">Weight of evidence</CardTitle>
          </CardHeader>
          <CardContent>
            <FactorList factors={report.score.factors} />
          </CardContent>
        </Card>
      ) : null}

      <section aria-labelledby="chart" className="grid gap-3">
        <h2 id="chart" className="text-lg font-semibold tracking-tight">
          The chart you uploaded
        </h2>
        {/* Overlays need the pixel calibration that Phase 4B produces; until then this is the
            plain screenshot, so nothing is drawn in the wrong place. */}
        <div className="overflow-hidden rounded-lg border bg-muted/30">
          {/* eslint-disable-next-line @next/next/no-img-element -- private image behind the session cookie; the Next optimizer can't fetch it */}
          <img
            src={`/api/v1/charts/${report.chart_upload_id}/image`}
            alt={`Uploaded chart for ${report.stock?.symbol ?? "this analysis"}`}
            className="h-auto w-full"
          />
        </div>
      </section>

      <AiVsActual analysis={report} />

      <ReportTabs analysis={report} />

      <footer className="grid gap-1 border-t pt-4 text-xs text-muted-foreground">
        <p>
          Narrative source: {report.narrative_source ?? "template"} · engine{" "}
          {report.engine_version ?? "unknown"}
        </p>
        <p>
          This is a technical analysis of price data, not investment advice. Setups fail; position
          sizing and the stop are what keep a wrong read survivable.
        </p>
      </footer>
    </article>
  );
}

function LoadingReport() {
  return (
    <div className="grid gap-4" aria-busy="true" aria-label="Loading the analysis">
      <Skeleton className="h-10 w-64" />
      <Skeleton className="h-32 w-full" />
      <Skeleton className="h-64 w-full" />
    </div>
  );
}
