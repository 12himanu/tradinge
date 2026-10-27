"use client";

import { Loader2, Sparkles } from "lucide-react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { useRequestAnalysis } from "@/features/analysis/queries";
import type { ChartUpload } from "@/lib/api/types";

/**
 * Queue the analysis for an uploaded chart and follow it to the report.
 *
 * A chart that already has a finished analysis links straight to it: re-running identical inputs
 * would produce the same answer (RequestAnalysis reuses it anyway).
 */
export function AnalyzeButton({ upload }: { upload: ChartUpload }) {
  const router = useRouter();
  const request = useRequestAnalysis();

  const existing = upload.analysis;
  const finished = existing && ["completed", "partial"].includes(existing.status);

  if (finished) {
    return (
      <Button onClick={() => router.push(`/analysis/${existing.id}`)}>
        <Sparkles /> Open the analysis
      </Button>
    );
  }

  if (existing && ["queued", "running"].includes(existing.status)) {
    return (
      <Button onClick={() => router.push(`/analysis/${existing.id}`)}>
        <Loader2 className="animate-spin" /> Follow the run
      </Button>
    );
  }

  return (
    <Button
      disabled={request.isPending}
      onClick={() =>
        request.mutate(
          {
            chartId: upload.id,
            body: {
              timeframe: upload.declared.timeframe ?? undefined,
              trading_style: upload.declared.trading_style ?? undefined,
            },
          },
          {
            onSuccess: (analysis) => router.push(`/analysis/${analysis.id}`),
            onError: (error) => toast.error(error.message),
          },
        )
      }
    >
      {request.isPending ? <Loader2 className="animate-spin" /> : <Sparkles />}
      Analyze this chart
    </Button>
  );
}
