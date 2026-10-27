import { apiFetch } from "@/lib/api/client";
import type { Envelope, ExitReason, Paginated, Trade, TradeStatus } from "@/lib/api/types";

export type TradeFilters = {
  status?: TradeStatus;
  stock_id?: number;
  tag?: string;
  per_page?: number;
  page?: number;
};

export type NewTrade = {
  stock_id: number;
  analysis_id?: string;
  prediction_id?: string;
  side: "long" | "short";
  entry_date: string;
  entry_price: number;
  quantity: number;
  stop_loss?: number;
  target_price?: number;
  strategy?: string;
  /** Required: a journal entry without a reason teaches nothing later. */
  reason: string;
  tags?: string[];
  confidence_level?: number;
};

export type CloseTrade = {
  exit_price: number;
  exit_date?: string;
  exit_reason: ExitReason;
  fees?: number;
  followed_plan?: boolean;
  lessons?: string;
};

export async function getTrades(
  filters: TradeFilters = {},
  signal?: AbortSignal,
): Promise<Paginated<Trade>> {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== "") params.set(key, String(value));
  }
  const query = params.toString();
  return apiFetch<Paginated<Trade>>(`/trades${query ? `?${query}` : ""}`, { signal });
}

export async function getTrade(id: string, signal?: AbortSignal): Promise<Trade> {
  return (await apiFetch<Envelope<Trade>>(`/trades/${id}`, { signal })).data;
}

export async function createTrade(body: NewTrade): Promise<Trade> {
  return (await apiFetch<Envelope<Trade>>("/trades", { json: body })).data;
}

export async function updateTrade(id: string, body: Partial<NewTrade>): Promise<Trade> {
  return (await apiFetch<Envelope<Trade>>(`/trades/${id}`, { method: "PATCH", json: body })).data;
}

export async function closeTrade(id: string, body: CloseTrade): Promise<Trade> {
  return (await apiFetch<Envelope<Trade>>(`/trades/${id}/close`, { json: body })).data;
}

export async function deleteTrade(id: string): Promise<void> {
  await apiFetch(`/trades/${id}`, { method: "DELETE" });
}
