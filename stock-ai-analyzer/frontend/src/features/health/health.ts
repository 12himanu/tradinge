"use client";

import { useQuery } from "@tanstack/react-query";

import { apiFetch } from "@/lib/api/client";
import type { CheckStatus, Envelope, Health } from "@/lib/api/types";

export function useHealth() {
  return useQuery({
    queryKey: ["health"],
    queryFn: async ({ signal }) => (await apiFetch<Envelope<Health>>("/health", { signal })).data,
    refetchInterval: 30_000,
    staleTime: 15_000,
  });
}

/** Plain-language problems, most fundamental first. Empty when everything is ok. */
export function describeProblems(health: Health): string[] {
  const problems: string[] = [];
  const { database, redis, engine } = health.checks;

  if (database.status !== "ok") problems.push("Database unavailable");
  if (redis.status !== "ok") problems.push("Redis unavailable");

  if (engine.status === "down") problems.push("AI engine offline");
  else if (engine.ollama && !engine.ollama.reachable) problems.push("Ollama isn't running");
  else if (engine.status !== "ok") problems.push("AI engine degraded");

  return problems;
}

export const STATUS_LABEL: Record<CheckStatus, string> = {
  ok: "OK",
  degraded: "Degraded",
  down: "Down",
};

export const STATUS_DOT: Record<CheckStatus, string> = {
  ok: "bg-emerald-500",
  degraded: "bg-amber-500",
  down: "bg-red-500",
};
