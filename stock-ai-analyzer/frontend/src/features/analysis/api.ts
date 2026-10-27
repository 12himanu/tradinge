import { apiFetch } from "@/lib/api/client";
import type {
  Analysis,
  AnalysisProgress,
  AnalysisSummary,
  DecisionAction,
  Envelope,
  Paginated,
  Timeframe,
  TradingStyle,
} from "@/lib/api/types";

export type RequestAnalysisBody = {
  timeframe?: Timeframe;
  trading_style?: TradingStyle;
  position_status?: "none" | "holding";
  position_entry_price?: number;
  position_quantity?: number;
  user_thesis?: string;
  force?: boolean;
  /**
   * A ticker to attach to an upload that has none.
   *
   * Re-uploading the same image cannot fix a symbol-less chart — the sha256 dedupe hands back the
   * original row and drops what was typed — so the correction happens here instead.
   */
  symbol?: string;
  exchange?: string;
  /**
   * "I have looked at the chart; it really is this symbol."
   *
   * The only way past the engine's chart-identity check, which refuses a screenshot it reads as
   * another company. Re-running cannot overrule it — the same image reads the same way.
   */
  confirm_symbol?: boolean;
};

export async function requestAnalysis(
  chartId: string,
  body: RequestAnalysisBody = {},
): Promise<Analysis> {
  return (await apiFetch<Envelope<Analysis>>(`/charts/${chartId}/analyze`, { json: body })).data;
}

export async function getAnalysis(id: string, signal?: AbortSignal): Promise<Analysis> {
  return (await apiFetch<Envelope<Analysis>>(`/analysis/${id}`, { signal })).data;
}

export async function getAnalysisProgress(
  id: string,
  signal?: AbortSignal,
): Promise<AnalysisProgress> {
  return (await apiFetch<Envelope<AnalysisProgress>>(`/analysis/${id}/status`, { signal })).data;
}

export async function cancelAnalysis(id: string): Promise<Analysis> {
  return (await apiFetch<Envelope<Analysis>>(`/analysis/${id}/cancel`, { json: {} })).data;
}

export type FeedbackBody = {
  rating: 1 | -1;
  issue_type?: string;
  comment?: string;
};

export async function sendFeedback(id: string, body: FeedbackBody): Promise<void> {
  await apiFetch(`/analysis/${id}/feedback`, { json: body });
}

export type HistoryFilters = {
  decision?: DecisionAction;
  bias?: string;
  tier?: string;
  status?: string;
  stock_id?: number;
  per_page?: number;
  page?: number;
};

export async function getHistory(
  filters: HistoryFilters = {},
  signal?: AbortSignal,
): Promise<Paginated<AnalysisSummary>> {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== "") params.set(key, String(value));
  }
  const query = params.toString();
  return apiFetch<Paginated<AnalysisSummary>>(`/history${query ? `?${query}` : ""}`, { signal });
}
