import type { Metadata } from "next";

import { ScannerTable } from "@/features/scanner/scanner-table";

export const metadata: Metadata = { title: "Scanner" };

export default function ScannerPage() {
  return (
    <div className="grid gap-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Scanner</h1>
        <p className="mt-1 max-w-3xl text-muted-foreground">
          Every NEPSE stock read by the engine after the close: structure, the traps that make an
          obvious chart a poor trade, the places a move could be joined, and what each signal has
          been worth on that symbol before. Scores rank setups; only the base rates are
          probabilities, and each carries the sample it was measured on.
        </p>
      </div>
      <ScannerTable />
    </div>
  );
}
