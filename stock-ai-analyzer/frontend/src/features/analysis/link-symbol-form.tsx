"use client";

import { useMutation } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { SymbolAutocomplete } from "@/features/market/symbol-autocomplete";
import type { ApiError } from "@/lib/api/client";

import { requestAnalysis } from "./api";

/**
 * Attach a ticker to a chart that was uploaded without one, and run it again.
 *
 * The report used to say "upload the chart again and enter the symbol", which does not work: the
 * upload deduplicates on the image hash, so the same file returns the original symbol-less row and
 * discards whatever was typed the second time. The chart is already stored — the only thing
 * missing is one field, so it is asked for here.
 */
export function LinkSymbolForm({
  chartUploadId,
  exchange,
}: {
  chartUploadId: string;
  exchange?: string;
}) {
  const router = useRouter();
  const [symbol, setSymbol] = useState("");

  const run = useMutation({
    mutationFn: (ticker: string) =>
      // Forced: an earlier vision-only run of this chart is not the answer to the new question.
      requestAnalysis(chartUploadId, { symbol: ticker, exchange, force: true }),
    onSuccess: (analysis, ticker) => {
      // The symbol is only applied when it resolves to a stock we hold. Anything else — a typo, a
      // ticker missing from the imported list — leaves the chart on whatever it had, so comparing
      // what came back with what was asked for is the only way to know it took.
      if (analysis.stock === null || analysis.stock.symbol !== ticker.trim().toUpperCase()) {
        toast.error("That symbol is not one we know", {
          description: "Check the spelling, or import the symbol list from Market data.",
        });
        return;
      }
      toast.success(`Linked to ${analysis.stock.symbol}`, {
        description: "Running the analysis again with its price history.",
      });
      router.push(`/analysis/${analysis.id}`);
    },
    onError: (error: ApiError) =>
      toast.error("Could not run it again", { description: error.message }),
  });

  const trimmed = symbol.trim();

  return (
    <form
      className="grid gap-2 sm:max-w-sm"
      onSubmit={(event) => {
        event.preventDefault();
        if (trimmed !== "") run.mutate(trimmed);
      }}
    >
      <Label htmlFor="link-symbol">Which symbol is this chart?</Label>
      <SymbolAutocomplete
        id="link-symbol"
        value={symbol}
        onChange={setSymbol}
        onSelect={(stock) => setSymbol(stock.symbol)}
        exchange={exchange}
        placeholder="For example NABIL"
      />
      <Button type="submit" size="sm" className="w-fit" disabled={trimmed === "" || run.isPending}>
        {run.isPending ? "Running…" : "Link and run again"}
      </Button>
    </form>
  );
}
