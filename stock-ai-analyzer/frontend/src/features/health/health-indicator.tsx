"use client";

import Link from "next/link";

import { cn } from "@/lib/utils";

import { describeProblems, STATUS_DOT, useHealth } from "./health";

/** Header status pill: green when DB, Redis, the engine and Ollama are all up. */
export function HealthIndicator() {
  const { data, isError, isPending } = useHealth();

  const status = isError ? "down" : data?.status;
  const problems = data ? describeProblems(data) : isError ? ["Backend unreachable"] : [];
  const label = isPending
    ? "Checking…"
    : status === "ok"
      ? "All systems OK"
      : (problems[0] ?? "Degraded");

  return (
    <Link
      href="/dashboard#system-status"
      title={problems.length > 1 ? problems.join(" · ") : undefined}
      className="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
    >
      <span
        aria-hidden
        className={cn(
          "size-2 rounded-full",
          status ? STATUS_DOT[status] : "bg-muted-foreground/40",
        )}
      />
      <span>{label}</span>
    </Link>
  );
}
