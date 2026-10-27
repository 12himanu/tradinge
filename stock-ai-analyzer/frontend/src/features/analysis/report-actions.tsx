"use client";

import { Copy, RefreshCw, ThumbsDown, ThumbsUp } from "lucide-react";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

import { useRequestAnalysis, useSendFeedback } from "./queries";
import type { Analysis } from "@/lib/api/types";

const ISSUES = [
  { value: "wrong_symbol", label: "Wrong symbol" },
  { value: "wrong_levels", label: "Levels are off" },
  { value: "wrong_pattern", label: "Pattern is wrong" },
  { value: "misread_chart", label: "Misread the chart" },
  { value: "not_useful", label: "Not useful" },
];

/** Copy, re-run and rate (P5-10). Feedback grows the golden set in Phase 6. */
export function ReportActions({ analysis }: { analysis: Analysis }) {
  const router = useRouter();
  const rerun = useRequestAnalysis();
  const feedback = useSendFeedback(analysis.id);
  const [rated, setRated] = useState<1 | -1 | null>(null);

  async function copyMarkdown() {
    if (!analysis.report_markdown) return;
    try {
      await navigator.clipboard.writeText(analysis.report_markdown);
      toast.success("Report copied as Markdown");
    } catch {
      toast.error("Your browser blocked clipboard access");
    }
  }

  function rate(rating: 1 | -1, issue?: string) {
    setRated(rating);
    feedback.mutate(
      { rating, issue_type: issue },
      {
        onSuccess: () => toast.success("Thanks — that helps calibrate the engine"),
        onError: (error) => toast.error(error.message),
      },
    );
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <Button variant="outline" size="sm" onClick={copyMarkdown} disabled={!analysis.report_markdown}>
        <Copy /> Copy as Markdown
      </Button>

      <Button
        variant="outline"
        size="sm"
        disabled={rerun.isPending}
        onClick={() =>
          rerun.mutate(
            {
              chartId: analysis.chart_upload_id,
              body: {
                timeframe: analysis.timeframe,
                trading_style: analysis.trading_style,
                force: true,
              },
            },
            {
              onSuccess: (fresh) => {
                toast.success("Running it again");
                router.push(`/analysis/${fresh.id}`);
              },
              onError: (error) => toast.error(error.message),
            },
          )
        }
      >
        <RefreshCw className={rerun.isPending ? "animate-spin" : undefined} /> Re-run
      </Button>

      <div className="ml-auto flex items-center gap-2">
        <Button
          variant={rated === 1 ? "secondary" : "ghost"}
          size="sm"
          aria-label="This analysis was useful"
          onClick={() => rate(1)}
        >
          <ThumbsUp />
        </Button>
        <Select
          value={rated === -1 ? undefined : ""}
          onValueChange={(issue) => rate(-1, issue)}
        >
          <SelectTrigger size="sm" aria-label="Report a problem with this analysis" className="w-44">
            <ThumbsDown className="size-3.5" />
            <SelectValue placeholder="Report a problem" />
          </SelectTrigger>
          <SelectContent>
            {ISSUES.map((issue) => (
              <SelectItem key={issue.value} value={issue.value}>
                {issue.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
    </div>
  );
}
