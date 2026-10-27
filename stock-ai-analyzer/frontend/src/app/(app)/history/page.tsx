import type { Metadata } from "next";

import { HistoryTable } from "@/features/analysis/history-table";

export const metadata: Metadata = { title: "History" };

export default function HistoryPage() {
  return (
    <div className="grid gap-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">History</h1>
        <p className="mt-1 text-muted-foreground">
          Every analysis you have run, with the decision it reached and the data it had.
        </p>
      </div>
      <HistoryTable />
    </div>
  );
}
