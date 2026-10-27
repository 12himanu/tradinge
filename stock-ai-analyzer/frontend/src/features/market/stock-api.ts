import { apiFetch } from "@/lib/api/client";
import type { Envelope, Paginated, PriceBar, Stock, Timeframe } from "@/lib/api/types";

export type PriceBarsQuery = {
  timeframe?: Timeframe;
  from?: string;
  to?: string;
  limit?: number;
};

/** Resolve a ticker to a stock, so pages can live at /stocks/PCBL rather than /stocks/14. */
export async function getStockBySymbol(symbol: string, signal?: AbortSignal): Promise<Stock> {
  return (
    await apiFetch<Envelope<Stock>>(`/stocks/symbol/${encodeURIComponent(symbol)}`, { signal })
  ).data;
}

export type PriceBarsPage = Paginated<PriceBar> & {
  meta?: { stock: { id: number; symbol: string }; timeframe: Timeframe; count: number };
};

export async function getPriceBars(
  stockId: number,
  query: PriceBarsQuery = {},
  signal?: AbortSignal,
): Promise<PriceBarsPage> {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== "") params.set(key, String(value));
  }
  const suffix = params.toString();
  return apiFetch<PriceBarsPage>(
    `/stocks/${stockId}/prices${suffix === "" ? "" : `?${suffix}`}`,
    { signal },
  );
}
