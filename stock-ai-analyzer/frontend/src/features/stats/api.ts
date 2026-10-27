import { apiFetch } from "@/lib/api/client";
import type {
  Calibration,
  Envelope,
  JournalAnalytics,
  PerformanceStats,
  PricePath,
} from "@/lib/api/types";

export const GROUPINGS = [
  "overall",
  "pattern",
  "style",
  "decision",
  "score_bucket",
  "data_tier",
] as const;
export type Grouping = (typeof GROUPINGS)[number];

export type StatsFilters = {
  group_by?: Grouping;
  engine_version?: string;
  style?: string;
  tier?: string;
  from?: string;
  to?: string;
};

export async function getPerformance(
  filters: StatsFilters = {},
  signal?: AbortSignal,
): Promise<PerformanceStats> {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== "") params.set(key, String(value));
  }
  const query = params.toString();
  return (
    await apiFetch<Envelope<PerformanceStats>>(`/stats/performance${query ? `?${query}` : ""}`, {
      signal,
    })
  ).data;
}

export async function getCalibration(
  filters: { engine_version?: string; scope?: string } = {},
  signal?: AbortSignal,
): Promise<Calibration> {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== "") params.set(key, String(value));
  }
  const query = params.toString();
  return (
    await apiFetch<Envelope<Calibration>>(`/stats/calibration${query ? `?${query}` : ""}`, {
      signal,
    })
  ).data;
}

export async function getJournalAnalytics(signal?: AbortSignal): Promise<JournalAnalytics> {
  return (await apiFetch<Envelope<JournalAnalytics>>("/stats/journal", { signal })).data;
}

export async function getPricePath(analysisId: string, signal?: AbortSignal): Promise<PricePath> {
  return (await apiFetch<Envelope<PricePath>>(`/analysis/${analysisId}/price-path`, { signal }))
    .data;
}
