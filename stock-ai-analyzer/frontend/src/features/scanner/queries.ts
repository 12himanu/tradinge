"use client";

import { useQuery, type UseQueryResult } from "@tanstack/react-query";

import type { ApiError } from "@/lib/api/client";
import type { MarketScan, MarketScanPage } from "@/lib/api/types";

import { getMarketScan, getStockScan, type ScannerFilters } from "./api";

export const scannerKeys = {
  all: ["market-scan"] as const,
  list: (filters: ScannerFilters) => ["market-scan", filters] as const,
  detail: (stockId: number) => ["market-scan", "detail", stockId] as const,
};

export function useMarketScan(
  filters: ScannerFilters = {},
): UseQueryResult<MarketScanPage, ApiError> {
  return useQuery({
    queryKey: scannerKeys.list(filters),
    queryFn: ({ signal }) => getMarketScan(filters, signal),
    // Keep the old rows on screen while a filter change loads, so the table does not flash empty.
    placeholderData: (previous) => previous,
  });
}

export function useStockScan(stockId: number | null): UseQueryResult<MarketScan, ApiError> {
  return useQuery({
    queryKey: scannerKeys.detail(stockId ?? 0),
    queryFn: ({ signal }) => getStockScan(stockId as number, signal),
    enabled: stockId !== null,
  });
}
