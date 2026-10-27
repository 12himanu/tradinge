import type { Metadata } from "next";

import { ImportPanel } from "@/features/market/import-panel";

export const metadata: Metadata = { title: "Market data" };

export default function MarketDataPage() {
  return (
    <div className="grid gap-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Market data</h1>
        <p className="mt-1 text-muted-foreground">
          Real price history lets analyses compute indicators from actual OHLCV data instead of
          reading them off the screenshot.
        </p>
      </div>
      <ImportPanel />
    </div>
  );
}
