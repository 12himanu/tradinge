import { Upload } from "lucide-react";
import type { Metadata } from "next";
import Link from "next/link";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { PredictionsPanel } from "@/features/analysis/predictions-panel";
import { RecentAnalyses } from "@/features/analysis/recent-analyses";
import { SystemStatus } from "@/features/health/system-status";
import { SyncPanel } from "@/features/market/sync-panel";
import { Hotlist } from "@/features/scanner/hotlist";

export const metadata: Metadata = { title: "Dashboard" };

export default function DashboardPage() {
  return (
    <div className="grid gap-8">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Dashboard</h1>
        <p className="mt-1 text-muted-foreground">
          What the engine says to do today, and the predictions still being scored.
        </p>
      </div>

      <section aria-labelledby="hotlist" className="grid gap-3">
        <div className="flex items-center justify-between">
          <h2 id="hotlist" className="text-lg font-semibold tracking-tight">
            Hotlist
          </h2>
          <Button asChild variant="ghost" size="sm">
            <Link href="/scanner">Open the scanner</Link>
          </Button>
        </div>
        <Hotlist perGroup={5} />
      </section>

      <div className="grid gap-4 md:grid-cols-2">
        <SyncPanel />

        <Card>
          <CardHeader>
            <CardTitle>Analyse a chart</CardTitle>
            <CardDescription>
              Paste a screenshot, drop it, or pick a file, and add the symbol and timeframe.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Button asChild>
              <Link href="/analyze">
                <Upload aria-hidden />
                Go to Analyze
              </Link>
            </Button>
          </CardContent>
        </Card>
      </div>

      <section aria-labelledby="recent" className="grid gap-3">
        <div className="flex items-center justify-between">
          <h2 id="recent" className="text-lg font-semibold tracking-tight">
            Recent analyses
          </h2>
          <Button asChild variant="ghost" size="sm">
            <Link href="/history">See all</Link>
          </Button>
        </div>
        <RecentAnalyses />
      </section>

      <section aria-labelledby="predictions" className="grid gap-3">
        <h2 id="predictions" className="text-lg font-semibold tracking-tight">
          Open predictions
        </h2>
        <p className="text-sm text-muted-foreground">
          Each one is scored against real bars after its as-of date, win or lose.
        </p>
        <PredictionsPanel limit={5} />
      </section>

      <SystemStatus />
    </div>
  );
}
