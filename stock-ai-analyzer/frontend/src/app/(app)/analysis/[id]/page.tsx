import type { Metadata } from "next";

import { AnalysisView } from "@/features/analysis/analysis-view";

export const metadata: Metadata = { title: "Analysis" };

/** Dynamic params are async in Next 16 (node_modules/next/dist/docs/01-app/01-getting-started). */
export default async function AnalysisPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return <AnalysisView id={id} />;
}
