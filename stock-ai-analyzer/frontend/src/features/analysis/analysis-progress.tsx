"use client";

import { AlertTriangle, Check, Loader2, X } from "lucide-react";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { cn } from "@/lib/utils";

import { explainError, STAGES, stageLabel } from "./display";
import { LinkSymbolForm } from "./link-symbol-form";
import { useCancelAnalysis, useRequestAnalysis } from "./queries";
import type { AnalysisProgress as Progress_ } from "@/lib/api/types";

type Props = {
  progress: Progress_;
  onRetry?: () => void;
  retrying?: boolean;
  /** Lets a symbol mismatch be corrected here, which is the only thing that fixes one. */
  chartUploadId?: string;
  /** The symbol the refused run was for, so it can be stood behind as well as corrected. */
  declaredSymbol?: string | null;
};

function useElapsed(active: boolean): number {
  const [seconds, setSeconds] = useState(0);

  useEffect(() => {
    if (!active) return;
    const started = Date.now();
    const timer = setInterval(() => setSeconds(Math.floor((Date.now() - started) / 1000)), 1000);
    return () => clearInterval(timer);
  }, [active]);

  return seconds;
}

/** Live stage checklist while the engine works (P5-03). */
export function AnalysisProgressView({
  progress,
  onRetry,
  retrying = false,
  chartUploadId,
  declaredSymbol,
}: Props) {
  const router = useRouter();
  const cancel = useCancelAnalysis(progress.id);
  const confirm = useRequestAnalysis();
  const running = !progress.terminal;
  const elapsed = useElapsed(running);
  const currentIndex = STAGES.findIndex((stage) => stage.key === progress.stage);

  if (progress.status === "failed" || progress.status === "cancelled") {
    const cancelled = progress.status === "cancelled";
    // Running it again would fail the same way: the chart is of one company and the analysis was
    // asked for another. Naming the right symbol is the only thing that changes the answer, so
    // that form takes the place of the retry button.
    const mismatch = progress.error_code === "SYMBOL_MISMATCH" && chartUploadId !== undefined;

    return (
      <Alert variant={cancelled ? "default" : "destructive"}>
        <AlertTriangle />
        <AlertTitle>
          {cancelled
            ? "Analysis cancelled"
            : mismatch
              ? "That chart is a different stock"
              : "Analysis failed"}
        </AlertTitle>
        <AlertDescription className="grid gap-3">
          <p>
            {cancelled
              ? "Nothing was stored. You can run it again whenever you like."
              : explainError(progress.error_code, progress.error_message)}
          </p>
          {mismatch ? (
            <>
              <p>
                Nothing was analysed. Name the symbol the screenshot actually shows and it will run
                again against that stock&apos;s price history.
              </p>
              <LinkSymbolForm chartUploadId={chartUploadId} />
              {declaredSymbol ? (
                <div className="grid gap-1">
                  <Button
                    size="sm"
                    variant="ghost"
                    className="w-fit"
                    disabled={confirm.isPending}
                    onClick={() =>
                      confirm.mutate(
                        {
                          chartId: chartUploadId,
                          // Re-running alone would read the same image the same way; the
                          // confirmation is what changes the answer.
                          body: { confirm_symbol: true, force: true },
                        },
                        { onSuccess: (fresh) => router.push(`/analysis/${fresh.id}`) },
                      )
                    }
                  >
                    {confirm.isPending ? <Loader2 className="animate-spin" /> : null}
                    It really is {declaredSymbol} — analyse it anyway
                  </Button>
                  <p className="text-xs text-muted-foreground">
                    The chart won&apos;t be checked again, and the report will say so.
                  </p>
                </div>
              ) : null}
            </>
          ) : onRetry ? (
            <Button size="sm" variant="outline" onClick={onRetry} disabled={retrying}>
              {retrying ? <Loader2 className="animate-spin" /> : null}
              Run it again
            </Button>
          ) : null}
        </AlertDescription>
      </Alert>
    );
  }

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between gap-4">
        <CardTitle className="flex items-center gap-2 text-base">
          <Loader2 className="size-4 animate-spin" aria-hidden />
          {stageLabel(progress.stage)}
        </CardTitle>
        <div className="flex items-center gap-3">
          <span className="tabular-nums text-sm text-muted-foreground" aria-live="polite">
            {elapsed}s
          </span>
          <Button
            size="sm"
            variant="ghost"
            onClick={() => cancel.mutate()}
            disabled={cancel.isPending}
          >
            <X /> Cancel
          </Button>
        </div>
      </CardHeader>
      <CardContent className="grid gap-4">
        <Progress
          value={Math.round(progress.progress * 100)}
          aria-label="Analysis progress"
          className="h-2"
        />
        <ol className="grid gap-1.5 text-sm">
          {STAGES.map((stage, index) => {
            const done = currentIndex > index;
            const active = currentIndex === index;
            return (
              <li
                key={stage.key}
                className={cn(
                  "flex items-center gap-2",
                  done && "text-muted-foreground",
                  !done && !active && "text-muted-foreground/60",
                )}
              >
                <span aria-hidden className="inline-flex size-4 items-center justify-center">
                  {done ? (
                    <Check className="size-3.5" />
                  ) : active ? (
                    <Loader2 className="size-3.5 animate-spin" />
                  ) : (
                    <span className="size-1.5 rounded-full bg-current opacity-40" />
                  )}
                </span>
                <span className={cn(active && "font-medium text-foreground")}>{stage.label}</span>
              </li>
            );
          })}
        </ol>
        {progress.status === "queued" ? (
          <p className="text-sm text-muted-foreground">Waiting for a worker to pick this up.</p>
        ) : null}
      </CardContent>
    </Card>
  );
}
