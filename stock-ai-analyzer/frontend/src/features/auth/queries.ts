"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { ApiError, apiFetch, ensureCsrfCookie } from "@/lib/api/client";
import type { Envelope, User } from "@/lib/api/types";

export const meQueryKey = ["me"] as const;

export function isUnauthenticated(error: unknown): boolean {
  return error instanceof ApiError && error.status === 401;
}

export function useMe() {
  return useQuery({
    queryKey: meQueryKey,
    queryFn: async ({ signal }) => (await apiFetch<Envelope<User>>("/me", { signal })).data,
    staleTime: 5 * 60_000,
  });
}

export type LoginInput = { email: string; password: string; remember: boolean };

export function useLogin() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: LoginInput) => {
      await ensureCsrfCookie(true);
      return (await apiFetch<Envelope<User>>("/auth/login", { json: input })).data;
    },
    onSuccess: (user) => queryClient.setQueryData(meQueryKey, user),
  });
}

export function useLogout() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => apiFetch<void>("/auth/logout", { method: "POST" }),
    onSettled: () => queryClient.clear(),
  });
}
