import { apiFetch } from "@/lib/api/client";
import type { Envelope, MarketScan, MarketScanPage } from "@/lib/api/types";

export type ScannerFilters = {
  bias?: string;
  decision?: string;
  range_zone?: string;
  min_score?: number;
  traps?: boolean;
  opportunities?: boolean;
  viable?: boolean;
  q?: string;
  sort?: string;
  per_page?: number;
  page?: number;
};

function toQuery(filters: ScannerFilters): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (value === undefined || value === "" || value === false) continue;
    params.set(key, typeof value === "boolean" ? "1" : String(value));
  }
  const query = params.toString();
  return query === "" ? "" : `?${query}`;
}

export async function getMarketScan(
  filters: ScannerFilters = {},
  signal?: AbortSignal,
): Promise<MarketScanPage> {
  return apiFetch<MarketScanPage>(`/market-scan${toQuery(filters)}`, { signal });
}

/** The full reading for one stock, including signals, base rates, levels and plans. */
export async function getStockScan(stockId: number, signal?: AbortSignal): Promise<MarketScan> {
  return (await apiFetch<Envelope<MarketScan>>(`/market-scan/${stockId}`, { signal })).data;
}
