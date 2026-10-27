"use client";

import { useState } from "react";

import { useMe } from "@/features/auth/queries";
import { TRADING_STYLES, type ChartUpload, type TradingStyle } from "@/lib/api/types";

import { UploadForm } from "./upload-form";
import { UploadResult } from "./upload-result";

function isTradingStyle(value: unknown): value is TradingStyle {
  return typeof value === "string" && (TRADING_STYLES as readonly string[]).includes(value);
}

export function UploadPanel() {
  const { data: user } = useMe();
  const [uploaded, setUploaded] = useState<ChartUpload | null>(null);

  if (uploaded) return <UploadResult upload={uploaded} onReset={() => setUploaded(null)} />;

  const style = user?.settings?.default_style;

  return (
    <UploadForm
      defaultExchange={user?.default_exchange?.code ?? "NEPSE"}
      defaultStyle={isTradingStyle(style) ? style : "swing"}
      onUploaded={setUploaded}
    />
  );
}
