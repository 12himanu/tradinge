"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Loader2 } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { meQueryKey, useMe } from "@/features/auth/queries";
import { useHealth } from "@/features/health/health";
import { apiFetch, type ApiError } from "@/lib/api/client";
import { TRADING_STYLES, type Envelope, type TradingStyle, type User, type UserSettings } from "@/lib/api/types";

async function updateSettings(settings: UserSettings): Promise<User> {
  return (await apiFetch<Envelope<User>>("/me/settings", { method: "PATCH", json: settings })).data;
}

/**
 * Account settings (P5-20).
 *
 * These are not cosmetic: the engine sizes every position from the account size and risk
 * percentage, and rejects plans whose R:R falls below the minimum. Changing them changes the
 * decisions the next analysis reaches.
 */
export function SettingsForm() {
  const me = useMe();
  const health = useHealth();
  const queryClient = useQueryClient();
  const [form, setForm] = useState<UserSettings | null>(null);

  const save = useMutation<User, ApiError, UserSettings>({
    mutationFn: updateSettings,
    onSuccess: (user) => {
      queryClient.setQueryData(meQueryKey, user);
      toast.success("Settings saved");
    },
    onError: (error) => toast.error(error.message),
  });

  if (me.isPending) return <Skeleton className="h-96 w-full" />;
  if (!me.data) return null;

  const stored = (me.data.settings ?? {}) as UserSettings;
  const values: UserSettings = { ...stored, ...form };

  function set<K extends keyof UserSettings>(key: K, value: UserSettings[K]) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  return (
    <form
      className="grid gap-6"
      onSubmit={(event) => {
        event.preventDefault();
        if (form) save.mutate(form);
      }}
    >
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Risk and sizing</CardTitle>
          <CardDescription>
            The engine sizes positions from these and rejects plans that breach them.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-2">
          <div className="grid gap-2">
            <Label htmlFor="account_size">Account size ({values.currency ?? "NPR"})</Label>
            <Input
              id="account_size"
              type="number"
              min="0"
              step="1000"
              value={values.account_size ?? ""}
              onChange={(event) =>
                set("account_size", event.target.value === "" ? null : Number(event.target.value))
              }
            />
            <p className="text-xs text-muted-foreground">
              Leave blank and the engine assumes a nominal account, so quantities are indicative only.
            </p>
          </div>

          <div className="grid gap-2">
            <Label htmlFor="risk_per_trade_pct">Risk per trade (%)</Label>
            <Input
              id="risk_per_trade_pct"
              type="number"
              min="0.1"
              max="10"
              step="0.1"
              value={values.risk_per_trade_pct ?? 1}
              onChange={(event) => set("risk_per_trade_pct", Number(event.target.value))}
            />
          </div>

          <div className="grid gap-2">
            <Label htmlFor="max_position_pct">Maximum position (% of account)</Label>
            <Input
              id="max_position_pct"
              type="number"
              min="1"
              max="100"
              step="1"
              value={values.max_position_pct ?? 25}
              onChange={(event) => set("max_position_pct", Number(event.target.value))}
            />
          </div>

          <div className="grid gap-2">
            <Label htmlFor="min_rr_override">Minimum R:R override</Label>
            <Input
              id="min_rr_override"
              type="number"
              min="1"
              max="10"
              step="0.1"
              placeholder="Use the style default"
              value={values.min_rr_override ?? ""}
              onChange={(event) =>
                set("min_rr_override", event.target.value === "" ? null : Number(event.target.value))
              }
            />
          </div>

          <div className="grid gap-2">
            <Label htmlFor="default_style">Default trading style</Label>
            <Select
              value={values.default_style ?? "swing"}
              onValueChange={(value) => set("default_style", value as TradingStyle)}
            >
              <SelectTrigger id="default_style">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {TRADING_STYLES.map((style) => (
                  <SelectItem key={style} value={style}>
                    {style.charAt(0).toUpperCase() + style.slice(1)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Engine</CardTitle>
          <CardDescription>Read-only: change the profile in the project&apos;s .env file.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-2 text-sm">
          <Row label="Model profile" value={health.data?.checks.engine.model_profile ?? "unknown"} />
          <Row label="Engine version" value={health.data?.checks.engine.version ?? "unknown"} />
          <Row
            label="Ollama"
            value={
              health.data?.checks.engine.ollama?.reachable
                ? `reachable (${health.data.checks.engine.ollama.version ?? "unknown version"})`
                : "not reachable"
            }
          />
          <Row label="Exchange" value={me.data.default_exchange?.name ?? "—"} />
        </CardContent>
      </Card>

      <div>
        <Button type="submit" disabled={!form || save.isPending}>
          {save.isPending ? <Loader2 className="animate-spin" /> : null}
          Save settings
        </Button>
      </div>
    </form>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-medium">{value}</span>
    </div>
  );
}
