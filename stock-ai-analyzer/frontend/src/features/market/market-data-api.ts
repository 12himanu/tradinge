import { apiFetch } from "@/lib/api/client";
import type { Envelope, Hotlist, MarketDataStatus } from "@/lib/api/types";

export async function getMarketDataStatus(signal?: AbortSignal): Promise<MarketDataStatus> {
  return (await apiFetch<Envelope<MarketDataStatus>>("/market-data/status", { signal })).data;
}

/** Queue a full price sync. The market scan chains itself on when it finishes. */
export async function startSync(): Promise<MarketDataStatus> {
  return (await apiFetch<Envelope<MarketDataStatus>>("/market-data/sync", { json: {} })).data;
}

/** Re-read every stock with the engine, without fetching bars first. */
export async function startScan(): Promise<MarketDataStatus> {
  return (await apiFetch<Envelope<MarketDataStatus>>("/market-data/scan", { json: {} })).data;
}

export async function getHotlist(perGroup = 5, signal?: AbortSignal): Promise<Hotlist> {
  return apiFetch<Hotlist>(`/market-scan/hotlist?per_page=${perGroup}`, { signal });
}
