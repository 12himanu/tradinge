import { apiFetch, apiUpload } from "@/lib/api/client";
import type { Envelope, ImportSummary, Stock, Timeframe } from "@/lib/api/types";

/** Exchanges seeded by the backend (ExchangeSeeder). NEPSE is the default market. */
export const EXCHANGES = ["NEPSE", "NASDAQ", "NSE"] as const;

export async function searchStocks(
  search: string,
  exchange?: string,
  signal?: AbortSignal,
): Promise<Stock[]> {
  const params = new URLSearchParams({ search });
  if (exchange) params.set("exchange", exchange);
  return (await apiFetch<Envelope<Stock[]>>(`/stocks?${params}`, { signal })).data;
}

export async function getStock(id: number, signal?: AbortSignal): Promise<Stock> {
  return (await apiFetch<Envelope<Stock>>(`/stocks/${id}`, { signal })).data;
}

export async function importPrices(
  stockId: number,
  file: File,
  timeframe: Timeframe,
  onProgress?: (fraction: number) => void,
): Promise<ImportSummary> {
  const form = new FormData();
  form.append("file", file);
  form.append("timeframe", timeframe);
  return (
    await apiUpload<Envelope<ImportSummary>>(`/stocks/${stockId}/prices/import`, form, {
      onProgress,
    })
  ).data;
}
