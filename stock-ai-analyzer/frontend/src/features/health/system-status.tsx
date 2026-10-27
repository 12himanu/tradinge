"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import type { CheckStatus } from "@/lib/api/types";
import { cn } from "@/lib/utils";

import { STATUS_DOT, STATUS_LABEL, useHealth } from "./health";

type Row = { name: string; status: CheckStatus; detail: string | null };

export function SystemStatus() {
  const { data, isError, error } = useHealth();

  const rows: Row[] = data
    ? [
        {
          name: "Database",
          status: data.checks.database.status,
          detail: latency(data.checks.database.latency_ms),
        },
        {
          name: "Redis",
          status: data.checks.redis.status,
          detail: latency(data.checks.redis.latency_ms),
        },
        {
          name: "AI engine",
          status: data.checks.engine.status === "down" ? "down" : "ok",
          detail:
            data.checks.engine.status === "down"
              ? (data.checks.engine.error ?? "Not reachable")
              : [
                  data.checks.engine.version,
                  data.checks.engine.model_profile && `profile ${data.checks.engine.model_profile}`,
                ]
                  .filter(Boolean)
                  .join(" · "),
        },
        {
          name: "Ollama",
          status: data.checks.engine.ollama?.reachable ? "ok" : "down",
          detail: data.checks.engine.ollama?.reachable
            ? `version ${data.checks.engine.ollama.version ?? "unknown"}`
            : (data.checks.engine.ollama?.error ?? "Status unknown while the engine is offline"),
        },
      ]
    : [];

  return (
    <Card id="system-status">
      <CardHeader>
        <CardTitle>System status</CardTitle>
        <CardDescription>Refreshes every 30 seconds.</CardDescription>
      </CardHeader>
      <CardContent>
        {isError ? (
          <p className="text-sm text-destructive">{error.message}</p>
        ) : (
          <ul className="divide-y">
            {rows.map((row) => (
              <li key={row.name} className="flex items-start gap-3 py-2.5 text-sm">
                <span
                  aria-hidden
                  className={cn("mt-1.5 size-2 shrink-0 rounded-full", STATUS_DOT[row.status])}
                />
                <span className="w-24 shrink-0 font-medium">{row.name}</span>
                <span className="w-20 shrink-0">{STATUS_LABEL[row.status]}</span>
                <span className="min-w-0 break-words text-muted-foreground">{row.detail}</span>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

function latency(ms: number | undefined): string | null {
  return ms === undefined ? null : `${Math.round(ms)} ms`;
}
