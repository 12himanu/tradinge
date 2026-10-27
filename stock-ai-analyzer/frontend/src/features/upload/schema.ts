import { z } from "zod";

import { TIMEFRAMES, TRADING_STYLES } from "@/lib/api/types";
import { formatBytes } from "@/lib/format";

// Mirrors the server (UploadChartRequest + config/stock_ai.php uploads) so most mistakes are caught
// before uploading. The server stays the authority: it sniffs the real type and re-checks everything.
export const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
export const MIN_WIDTH = 600;
export const MIN_HEIGHT = 400;
export const MAX_DIMENSION = 8000;
export const ACCEPTED_IMAGE_TYPES: readonly string[] = ["image/png", "image/jpeg", "image/webp"];

const SYMBOL_PATTERN = /^[A-Z0-9][A-Z0-9._&-]*$/;

/** Same normalization as the server: uppercase, all whitespace removed. */
export function normalizeSymbol(value: string): string {
  return value.replace(/\s+/g, "").toUpperCase();
}

export const DETECT_TIMEFRAME = "detect";

export const uploadFormSchema = z
  .object({
    symbol: z.string().refine((value) => {
      const symbol = normalizeSymbol(value);
      return symbol === "" || (symbol.length <= 20 && SYMBOL_PATTERN.test(symbol));
    }, "Use the ticker symbol, for example NABIL. Pick from the suggestions to be sure."),
    exchange: z.string().min(1, "Choose an exchange."),
    timeframe: z.union([z.enum(TIMEFRAMES), z.literal(DETECT_TIMEFRAME)]),
    trading_style: z.enum(TRADING_STYLES, { error: "Choose a trading style." }),
    position_status: z.enum(["none", "holding"]),
    position_entry_price: z.string().trim(),
    position_quantity: z.string().trim(),
    thesis: z.string().max(2000, "Keep the thesis under 2,000 characters."),
    auto_analyze: z.boolean(),
  })
  .superRefine((values, ctx) => {
    if (values.position_status !== "holding") return;

    const positive = (raw: string) => raw !== "" && Number.isFinite(Number(raw)) && Number(raw) > 0;
    if (!positive(values.position_entry_price)) {
      ctx.addIssue({
        code: "custom",
        path: ["position_entry_price"],
        message: "Enter your entry price.",
      });
    }
    if (!positive(values.position_quantity)) {
      ctx.addIssue({
        code: "custom",
        path: ["position_quantity"],
        message: "Enter how many shares you hold.",
      });
    }
  });

export type UploadFormValues = z.infer<typeof uploadFormSchema>;

export function validateImageFile(file: File): string | null {
  if (!ACCEPTED_IMAGE_TYPES.includes(file.type))
    return "Only PNG, JPEG and WebP screenshots are accepted.";
  if (file.size === 0) return "This file is empty.";
  if (file.size > MAX_IMAGE_BYTES) {
    return `The image is ${formatBytes(file.size)}; screenshots must be 10 MB or smaller.`;
  }
  return null;
}

export function validateDimensions(width: number, height: number): string | null {
  if (width < MIN_WIDTH || height < MIN_HEIGHT) {
    return `The image is ${width}×${height} px, too small to read reliably. Use a screenshot of at least ${MIN_WIDTH}×${MIN_HEIGHT} px.`;
  }
  if (width > MAX_DIMENSION || height > MAX_DIMENSION) {
    return `The image is ${width}×${height} px; the maximum is ${MAX_DIMENSION}×${MAX_DIMENSION} px.`;
  }
  return null;
}

/** Multipart body for POST /charts/upload. Position fields are sent only when holding. */
export function toFormData(file: File, values: UploadFormValues): FormData {
  const form = new FormData();
  form.append("image", file);
  form.append("trading_style", values.trading_style);
  form.append("exchange", values.exchange);
  form.append("position_status", values.position_status);
  form.append("auto_analyze", values.auto_analyze ? "1" : "0");

  const symbol = normalizeSymbol(values.symbol);
  if (symbol) form.append("symbol", symbol);
  if (values.timeframe !== DETECT_TIMEFRAME) form.append("timeframe", values.timeframe);
  if (values.thesis.trim()) form.append("thesis", values.thesis.trim());
  if (values.position_status === "holding") {
    form.append("position_entry_price", values.position_entry_price);
    form.append("position_quantity", values.position_quantity);
  }

  return form;
}
