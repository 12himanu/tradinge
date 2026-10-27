import type { Metadata } from "next";

import { CalibrationView } from "@/features/stats/calibration-view";
import { JournalAnalyticsPanel } from "@/features/stats/journal-analytics";
import { PerformanceView } from "@/features/stats/performance-view";

export const metadata: Metadata = { title: "Performance" };

export default function PerformancePage() {
  return (
    <div className="grid gap-8">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Performance</h1>
        <p className="mt-1 max-w-prose text-muted-foreground">
          How the app&apos;s own predictions turned out, and what your trading record says. Every
          rate comes with its interval and its sample size.
        </p>
      </div>

      <PerformanceView />

      <section aria-labelledby="calibration" className="grid gap-3">
        <h2 id="calibration" className="text-lg font-semibold tracking-tight">
          Calibration
        </h2>
        <CalibrationView />
      </section>

      <section aria-labelledby="journal-analytics" className="grid gap-3">
        <h2 id="journal-analytics" className="text-lg font-semibold tracking-tight">
          Your journal
        </h2>
        <JournalAnalyticsPanel />
      </section>
    </div>
  );
}
