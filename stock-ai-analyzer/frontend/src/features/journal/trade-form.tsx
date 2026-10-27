"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2 } from "lucide-react";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { SymbolAutocomplete } from "@/features/market/symbol-autocomplete";
import type { Analysis, Stock, TradePlan } from "@/lib/api/types";

import { useCreateTrade } from "./queries";

/**
 * A trade you actually took (P5-17).
 *
 * The stop and target ordering is validated here as well as in the API, so the form can point at
 * the wrong field rather than showing a server error after the fact.
 */
const schema = z
  .object({
    stock_id: z.number({ message: "Pick the stock you traded." }).int().positive(),
    side: z.enum(["long", "short"]),
    entry_date: z.string().min(1, "When did you enter?"),
    entry_price: z.number().positive("Entry price must be above zero."),
    quantity: z.number().positive("How many shares?"),
    stop_loss: z.number().positive().optional(),
    target_price: z.number().positive().optional(),
    strategy: z.string().max(100).optional(),
    reason: z.string().min(3, "Write down why you took this trade — that is the point of a journal."),
    tags: z.string().optional(),
  })
  .superRefine((values, context) => {
    const long = values.side === "long";
    if (values.stop_loss !== undefined) {
      const wrong = long ? values.stop_loss >= values.entry_price : values.stop_loss <= values.entry_price;
      if (wrong) {
        context.addIssue({
          code: "custom",
          path: ["stop_loss"],
          message: long ? "The stop must be below the entry." : "The stop must be above the entry.",
        });
      }
    }
    if (values.target_price !== undefined) {
      const wrong = long
        ? values.target_price <= values.entry_price
        : values.target_price >= values.entry_price;
      if (wrong) {
        context.addIssue({
          code: "custom",
          path: ["target_price"],
          message: long ? "The target must be above the entry." : "The target must be below the entry.",
        });
      }
    }
  });

type FormValues = z.input<typeof schema>;

type Props = {
  /** Prefills from an analysis plan, so the journal starts from what the engine proposed. */
  analysis?: Analysis;
  plan?: TradePlan;
  stock?: Stock | null;
  onCreated?: (id: string) => void;
};

export function TradeForm({ analysis, plan, stock, onCreated }: Props) {
  const create = useCreateTrade();
  const [symbol, setSymbol] = useState(stock?.symbol ?? analysis?.stock?.symbol ?? "");

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      stock_id: stock?.id ?? analysis?.stock?.id,
      side: "long",
      entry_date: new Date().toISOString().slice(0, 10),
      entry_price: plan?.entry_price,
      quantity: plan?.quantity,
      stop_loss: plan?.stop_price,
      target_price: plan?.targets?.[0],
      strategy: plan ? `${plan.entry_type} entry` : undefined,
      reason: analysis?.report?.decision?.rationale ?? "",
      tags: "",
    },
  });

  const errors = form.formState.errors;

  function onSubmit(values: FormValues) {
    const parsed = schema.parse(values);
    create.mutate(
      {
        ...parsed,
        analysis_id: analysis?.id,
        tags: parsed.tags
          ? parsed.tags
              .split(",")
              .map((tag) => tag.trim())
              .filter(Boolean)
          : undefined,
      },
      {
        onSuccess: (trade) => {
          toast.success("Trade recorded");
          form.reset();
          onCreated?.(trade.id);
        },
        onError: (error) => {
          for (const [field, messages] of Object.entries(error.fieldErrors)) {
            form.setError(field as keyof FormValues, { message: messages[0] });
          }
          toast.error(error.message);
        },
      },
    );
  }

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} className="grid gap-4">
      <div className="grid gap-2">
        <Label htmlFor="stock">Stock</Label>
        <SymbolAutocomplete
          id="stock"
          value={symbol}
          onChange={setSymbol}
          onSelect={(selected) => {
            setSymbol(selected.symbol);
            form.setValue("stock_id", selected.id, { shouldValidate: true });
          }}
          invalid={Boolean(errors.stock_id)}
        />
        {errors.stock_id ? <FieldError>{errors.stock_id.message}</FieldError> : null}
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="grid gap-2">
          <Label htmlFor="side">Side</Label>
          <Select
            value={form.watch("side")}
            onValueChange={(value) => form.setValue("side", value as "long" | "short")}
          >
            <SelectTrigger id="side">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="long">Long</SelectItem>
              <SelectItem value="short">Short</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <Field label="Entry date" error={errors.entry_date?.message}>
          <Input id="entry_date" type="date" {...form.register("entry_date")} />
        </Field>
        <Field label="Entry price" error={errors.entry_price?.message}>
          <Input
            id="entry_price"
            type="number"
            step="0.01"
            {...form.register("entry_price", { valueAsNumber: true })}
          />
        </Field>
        <Field label="Quantity" error={errors.quantity?.message}>
          <Input
            id="quantity"
            type="number"
            step="1"
            {...form.register("quantity", { valueAsNumber: true })}
          />
        </Field>
        <Field label="Stop loss" error={errors.stop_loss?.message}>
          <Input
            id="stop_loss"
            type="number"
            step="0.01"
            {...form.register("stop_loss", { valueAsNumber: true, setValueAs: emptyToUndefined })}
          />
        </Field>
        <Field label="Target" error={errors.target_price?.message}>
          <Input
            id="target_price"
            type="number"
            step="0.01"
            {...form.register("target_price", { valueAsNumber: true, setValueAs: emptyToUndefined })}
          />
        </Field>
      </div>

      <Field label="Strategy" error={errors.strategy?.message}>
        <Input id="strategy" placeholder="Breakout, pullback, …" {...form.register("strategy")} />
      </Field>

      <Field label="Why you took it" error={errors.reason?.message}>
        <Textarea id="reason" rows={3} {...form.register("reason")} />
      </Field>

      <Field label="Tags" error={errors.tags?.message}>
        <Input id="tags" placeholder="nepse, breakout" {...form.register("tags")} />
      </Field>

      <div>
        <Button type="submit" disabled={create.isPending}>
          {create.isPending ? <Loader2 className="animate-spin" /> : null}
          Record trade
        </Button>
      </div>
    </form>
  );
}

function emptyToUndefined(value: unknown): number | undefined {
  if (value === "" || value === null || value === undefined) return undefined;
  const parsed = Number(value);
  return Number.isNaN(parsed) ? undefined : parsed;
}

function Field({
  label,
  error,
  children,
}: {
  label: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="grid gap-2">
      <Label>{label}</Label>
      {children}
      {error ? <FieldError>{error}</FieldError> : null}
    </div>
  );
}

function FieldError({ children }: { children: React.ReactNode }) {
  return <p className="text-sm text-destructive">{children}</p>;
}
