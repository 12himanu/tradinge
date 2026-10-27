import type { Metadata } from "next";

import { ForecastView } from "@/features/forecast/forecast-view";

export const metadata: Metadata = { title: "Forecast" };

export default function ForecastPage() {
  return (
    <div className="grid gap-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Next day and next week</h1>
        <p className="mt-1 max-w-3xl text-muted-foreground">
          What one stock&apos;s own history says about the day and the week ahead, and what each
          kind of analysis — trend, momentum, market structure, volume, patterns, levels — makes of
          it right now. These are measurements of what happened before, printed with the sample they
          were measured on. Nothing on this page claims to know what happens next.
        </p>
      </div>
      <ForecastView />
    </div>
  );
}
