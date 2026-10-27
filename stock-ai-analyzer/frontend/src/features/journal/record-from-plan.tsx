"use client";

import { NotebookPen, X } from "lucide-react";
import { useRouter } from "next/navigation";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import type { Analysis, TradePlan } from "@/lib/api/types";

import { TradeForm } from "./trade-form";

/**
 * Journal a trade straight from the plan the analysis proposed (P5-17).
 *
 * Prefilling from the plan is what makes "plan vs execution" comparable later: if the user retypes
 * the numbers from memory, Phase 6 can only compare guesses.
 */
export function RecordFromPlan({ analysis, plan }: { analysis: Analysis; plan?: TradePlan }) {
  const router = useRouter();
  const [open, setOpen] = useState(false);

  if (!open) {
    return (
      <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
        <NotebookPen /> Record this trade
      </Button>
    );
  }

  return (
    <Card>
      <CardHeader className="flex-row items-start justify-between gap-3">
        <div>
          <CardTitle className="text-base">Record this trade</CardTitle>
          <CardDescription>
            {plan
              ? "Prefilled from the plan above. Change anything you actually did differently."
              : "No plan to prefill from, so fill in what you did."}
          </CardDescription>
        </div>
        <Button variant="ghost" size="sm" onClick={() => setOpen(false)} aria-label="Close the form">
          <X />
        </Button>
      </CardHeader>
      <CardContent>
        <TradeForm
          analysis={analysis}
          plan={plan}
          stock={analysis.stock}
          onCreated={() => {
            setOpen(false);
            router.push("/journal");
          }}
        />
      </CardContent>
    </Card>
  );
}
