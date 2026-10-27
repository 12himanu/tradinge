"use client";

import { useMutation, useQuery, useQueryClient, type UseQueryResult } from "@tanstack/react-query";

import type { ApiError } from "@/lib/api/client";
import type { Analysis, AnalysisProgress, AnalysisSummary, Paginated } from "@/lib/api/types";

import {
  cancelAnalysis,
  getAnalysis,
  getAnalysisProgress,
  getHistory,
  requestAnalysis,
  sendFeedback,
  type FeedbackBody,
  type HistoryFilters,
  type RequestAnalysisBody,
} from "./api";

/** While a run is in flight the status endpoint is cheap; the report is not (P5-03). */
export const POLL_INTERVAL_MS = 2000;

export const analysisKeys = {
  all: ["analysis"] as const,
  detail: (id: string) => ["analysis", id] as const,
  progress: (id: string) => ["analysis", id, "progress"] as const,
  history: (filters: HistoryFilters) => ["history", filters] as const,
};

export function useAnalysis(id: string, enabled = true): UseQueryResult<Analysis, ApiError> {
  return useQuery({
    queryKey: analysisKeys.detail(id),
    queryFn: ({ signal }) => getAnalysis(id, signal),
    enabled: enabled && id !== "",
  });
}

/**
 * Polls until the run reaches a terminal state, then stops. The report itself is fetched once,
 * by `useAnalysis`, when the poll reports it is done.
 */
export function useAnalysisProgress(
  id: string,
  enabled = true,
): UseQueryResult<AnalysisProgress, ApiError> {
  return useQuery({
    queryKey: analysisKeys.progress(id),
    queryFn: ({ signal }) => getAnalysisProgress(id, signal),
    enabled: enabled && id !== "",
    refetchInterval: (query) => (query.state.data?.terminal ? false : POLL_INTERVAL_MS),
    refetchIntervalInBackground: false,
  });
}

export function useRequestAnalysis() {
  const queryClient = useQueryClient();

  return useMutation<Analysis, ApiError, { chartId: string; body?: RequestAnalysisBody }>({
    mutationFn: ({ chartId, body }) => requestAnalysis(chartId, body),
    onSuccess: (analysis) => {
      queryClient.setQueryData(analysisKeys.detail(analysis.id), analysis);
      void queryClient.invalidateQueries({ queryKey: ["history"] });
    },
  });
}

export function useCancelAnalysis(id: string) {
  const queryClient = useQueryClient();

  return useMutation<Analysis, ApiError, void>({
    mutationFn: () => cancelAnalysis(id),
    onSuccess: (analysis) => {
      queryClient.setQueryData(analysisKeys.detail(id), analysis);
      void queryClient.invalidateQueries({ queryKey: analysisKeys.progress(id) });
    },
  });
}

export function useSendFeedback(id: string) {
  return useMutation<void, ApiError, FeedbackBody>({
    mutationFn: (body) => sendFeedback(id, body),
  });
}

export function useHistory(
  filters: HistoryFilters = {},
): UseQueryResult<Paginated<AnalysisSummary>, ApiError> {
  return useQuery({
    queryKey: analysisKeys.history(filters),
    queryFn: ({ signal }) => getHistory(filters, signal),
    placeholderData: (previous) => previous,
  });
}
