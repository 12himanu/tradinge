import type { Metadata } from "next";

import { UploadPanel } from "@/features/upload/upload-panel";

export const metadata: Metadata = { title: "Analyze" };

export default function AnalyzePage() {
  return (
    <div className="grid gap-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Analyze a chart</h1>
        <p className="mt-1 text-muted-foreground">
          Upload a screenshot from TradingView or a NEPSE charting site, with the context the
          analysis should use.
        </p>
      </div>
      <UploadPanel />
    </div>
  );
}
