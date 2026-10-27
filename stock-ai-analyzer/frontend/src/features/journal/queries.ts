"use client";

import { useMutation, useQuery, useQueryClient, type UseQueryResult } from "@tanstack/react-query";

import type { ApiError } from "@/lib/api/client";
import type { Paginated, Trade } from "@/lib/api/types";

import {
  closeTrade,
  createTrade,
  deleteTrade,
  getTrade,
  getTrades,
  updateTrade,
  type CloseTrade,
  type NewTrade,
  type TradeFilters,
} from "./api";

export const tradeKeys = {
  all: ["trades"] as const,
  list: (filters: TradeFilters) => ["trades", filters] as const,
  detail: (id: string) => ["trades", "detail", id] as const,
};

export function useTrades(filters: TradeFilters = {}): UseQueryResult<Paginated<Trade>, ApiError> {
  return useQuery({
    queryKey: tradeKeys.list(filters),
    queryFn: ({ signal }) => getTrades(filters, signal),
    placeholderData: (previous) => previous,
  });
}

export function useTrade(id: string): UseQueryResult<Trade, ApiError> {
  return useQuery({
    queryKey: tradeKeys.detail(id),
    queryFn: ({ signal }) => getTrade(id, signal),
    enabled: id !== "",
  });
}

function invalidate(queryClient: ReturnType<typeof useQueryClient>) {
  return () => {
    void queryClient.invalidateQueries({ queryKey: tradeKeys.all });
  };
}

export function useCreateTrade() {
  const queryClient = useQueryClient();

  return useMutation<Trade, ApiError, NewTrade>({
    mutationFn: createTrade,
    onSuccess: invalidate(queryClient),
  });
}

export function useUpdateTrade(id: string) {
  const queryClient = useQueryClient();

  return useMutation<Trade, ApiError, Partial<NewTrade>>({
    mutationFn: (body) => updateTrade(id, body),
    onSuccess: invalidate(queryClient),
  });
}

export function useCloseTrade(id: string) {
  const queryClient = useQueryClient();

  return useMutation<Trade, ApiError, CloseTrade>({
    mutationFn: (body) => closeTrade(id, body),
    onSuccess: invalidate(queryClient),
  });
}

export function useDeleteTrade() {
  const queryClient = useQueryClient();

  return useMutation<void, ApiError, string>({
    mutationFn: deleteTrade,
    onSuccess: invalidate(queryClient),
  });
}
