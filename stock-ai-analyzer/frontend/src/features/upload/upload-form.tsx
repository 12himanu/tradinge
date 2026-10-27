"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useCallback, useEffect, useState } from "react";
import { Controller, useForm, useWatch } from "react-hook-form";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Progress } from "@/components/ui/progress";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { EXCHANGES } from "@/features/market/api";
import { SymbolAutocomplete } from "@/features/market/symbol-autocomplete";
import { ApiError, apiUpload } from "@/lib/api/client";
import {
  TIMEFRAMES,
  TRADING_STYLES,
  type ChartUpload,
  type Envelope,
  type TradingStyle,
} from "@/lib/api/types";

import { ChartDropzone, loadImage, type SelectedImage } from "./chart-dropzone";
import {
  DETECT_TIMEFRAME,
  toFormData,
  uploadFormSchema,
  validateDimensions,
  validateImageFile,
  type UploadFormValues,
} from "./schema";
import { usePasteImage } from "./use-paste-image";

const STYLE_LABELS: Record<TradingStyle, string> = {
  swing: "Swing (days to weeks)",
  positional: "Positional (weeks to months)",
  investing: "Investing (months+)",
  intraday: "Intraday",
};

const IMAGE_ERROR_CODES = new Set([
  "FILE_TOO_LARGE",
  "PAYLOAD_TOO_LARGE",
  "UNSUPPORTED_IMAGE_TYPE",
  "INVALID_IMAGE",
  "INVALID_DIMENSIONS",
]);

type Props = {
  defaultExchange: string;
  defaultStyle: TradingStyle;
  onUploaded: (upload: ChartUpload) => void;
};

export function UploadForm({ defaultExchange, defaultStyle, onUploaded }: Props) {
  const [image, setImage] = useState<SelectedImage | null>(null);
  const [imageError, setImageError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [progress, setProgress] = useState<number | null>(null);
  const uploading = progress !== null;

  const form = useForm<UploadFormValues>({
    resolver: zodResolver(uploadFormSchema),
    defaultValues: {
      symbol: "",
      exchange: defaultExchange,
      timeframe: "1D",
      trading_style: defaultStyle,
      position_status: "none",
      position_entry_price: "",
      position_quantity: "",
      thesis: "",
      auto_analyze: false,
    },
  });
  const { errors } = form.formState;
  const [positionStatus, exchange, thesis] = useWatch({
    control: form.control,
    name: ["position_status", "exchange", "thesis"],
  });
  const holding = positionStatus === "holding";

  // Revoke the previous preview URL whenever the image changes, and on unmount.
  useEffect(
    () => () => {
      if (image) URL.revokeObjectURL(image.previewUrl);
    },
    [image],
  );

  const selectImage = useCallback(async (file: File) => {
    const typeError = validateImageFile(file);
    if (typeError) {
      setImageError(typeError);
      return;
    }
    const loaded = await loadImage(file);
    if (!loaded) {
      setImageError("This file couldn't be read as an image.");
      return;
    }
    setImage({ file, ...loaded });
    setImageError(validateDimensions(loaded.width, loaded.height));
  }, []);

  usePasteImage((file) => void selectImage(file), !uploading);

  const missingImage = () => {
    if (!image) setImageError("Add a chart screenshot: paste it, drop it here, or choose a file.");
  };

  const onSubmit = form.handleSubmit(async (values) => {
    if (!image) return missingImage();
    if (imageError) return;

    setFormError(null);
    setProgress(0);
    try {
      const { data } = await apiUpload<Envelope<ChartUpload>>(
        "/charts/upload",
        toFormData(image.file, values),
        {
          onProgress: setProgress,
        },
      );
      onUploaded(data);
    } catch (error) {
      showServerError(error);
    } finally {
      setProgress(null);
    }
  }, missingImage);

  function showServerError(error: unknown) {
    if (!(error instanceof ApiError)) {
      setFormError("Upload failed. Try again.");
      return;
    }
    if (IMAGE_ERROR_CODES.has(error.code)) {
      setImageError(error.message);
      return;
    }
    if (error.code !== "VALIDATION_FAILED") {
      setFormError(error.message);
      return;
    }

    const fields = Object.keys(form.getValues()) as (keyof UploadFormValues)[];
    let unmatched = false;
    for (const [field, messages] of Object.entries(error.fieldErrors)) {
      if (field === "image") setImageError(messages[0]);
      else if ((fields as string[]).includes(field))
        form.setError(field as keyof UploadFormValues, { message: messages[0] });
      else unmatched = true;
    }
    if (unmatched) setFormError(error.message);
  }

  return (
    <form
      onSubmit={onSubmit}
      noValidate
      className="grid gap-8 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]"
    >
      <ChartDropzone
        image={image}
        error={imageError}
        disabled={uploading}
        onFile={(file) => void selectImage(file)}
        onClear={() => {
          setImage(null);
          setImageError(null);
        }}
      />

      <div className="grid content-start gap-5">
        {formError && (
          <Alert variant="destructive">
            <AlertDescription>{formError}</AlertDescription>
          </Alert>
        )}

        <div className="grid grid-cols-[minmax(0,1fr)_7.5rem] gap-3">
          <div className="grid gap-2">
            <Label htmlFor="symbol">Symbol</Label>
            <Controller
              control={form.control}
              name="symbol"
              render={({ field }) => (
                <SymbolAutocomplete
                  id="symbol"
                  value={field.value}
                  onChange={field.onChange}
                  exchange={exchange}
                  invalid={Boolean(errors.symbol)}
                  describedBy={errors.symbol ? "symbol-error" : "symbol-hint"}
                  emptyHint="Not in the stock list. The symbol will be kept as typed."
                />
              )}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="exchange">Exchange</Label>
            <Controller
              control={form.control}
              name="exchange"
              render={({ field }) => (
                <Select value={field.value} onValueChange={field.onChange}>
                  <SelectTrigger id="exchange" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {EXCHANGES.map((code) => (
                      <SelectItem key={code} value={code}>
                        {code}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
          </div>
          <p
            id={errors.symbol ? "symbol-error" : "symbol-hint"}
            className={`col-span-2 -mt-1 text-sm ${errors.symbol ? "text-destructive" : "text-muted-foreground"}`}
          >
            {errors.symbol?.message ??
              "Optional. Linking the stock lets analyses use imported price data."}
          </p>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div className="grid gap-2">
            <Label htmlFor="timeframe">Timeframe</Label>
            <Controller
              control={form.control}
              name="timeframe"
              render={({ field }) => (
                <Select value={field.value} onValueChange={field.onChange}>
                  <SelectTrigger id="timeframe" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {TIMEFRAMES.map((timeframe) => (
                      <SelectItem key={timeframe} value={timeframe}>
                        {timeframe}
                      </SelectItem>
                    ))}
                    <SelectItem value={DETECT_TIMEFRAME}>Detect from chart</SelectItem>
                  </SelectContent>
                </Select>
              )}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="trading_style">Trading style</Label>
            <Controller
              control={form.control}
              name="trading_style"
              render={({ field }) => (
                <Select value={field.value} onValueChange={field.onChange}>
                  <SelectTrigger
                    id="trading_style"
                    className="w-full"
                    aria-invalid={errors.trading_style ? true : undefined}
                  >
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {TRADING_STYLES.map((style) => (
                      <SelectItem key={style} value={style}>
                        {STYLE_LABELS[style]}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
            {errors.trading_style && (
              <p className="text-sm text-destructive">{errors.trading_style.message}</p>
            )}
          </div>
        </div>

        <div className="grid gap-3 rounded-lg border p-4">
          <div className="flex items-center gap-3">
            <Controller
              control={form.control}
              name="position_status"
              render={({ field }) => (
                <Switch
                  id="holding"
                  checked={field.value === "holding"}
                  onCheckedChange={(checked) => field.onChange(checked ? "holding" : "none")}
                />
              )}
            />
            <Label htmlFor="holding" className="font-normal">
              I&apos;m holding this stock
            </Label>
          </div>
          {holding && (
            <div className="grid grid-cols-2 gap-3">
              <div className="grid gap-2">
                <Label htmlFor="position_entry_price">Entry price</Label>
                <Input
                  id="position_entry_price"
                  inputMode="decimal"
                  aria-invalid={errors.position_entry_price ? true : undefined}
                  {...form.register("position_entry_price")}
                />
                {errors.position_entry_price && (
                  <p className="text-sm text-destructive">{errors.position_entry_price.message}</p>
                )}
              </div>
              <div className="grid gap-2">
                <Label htmlFor="position_quantity">Quantity</Label>
                <Input
                  id="position_quantity"
                  inputMode="numeric"
                  aria-invalid={errors.position_quantity ? true : undefined}
                  {...form.register("position_quantity")}
                />
                {errors.position_quantity && (
                  <p className="text-sm text-destructive">{errors.position_quantity.message}</p>
                )}
              </div>
            </div>
          )}
        </div>

        <div className="grid gap-2">
          <div className="flex items-baseline justify-between">
            <Label htmlFor="thesis">Your thesis</Label>
            <span className="text-xs text-muted-foreground">{thesis.length}/2000</span>
          </div>
          <Textarea
            id="thesis"
            rows={3}
            placeholder="Optional. For example: breakout above 580 looks close."
            aria-invalid={errors.thesis ? true : undefined}
            {...form.register("thesis")}
          />
          {errors.thesis && <p className="text-sm text-destructive">{errors.thesis.message}</p>}
        </div>

        <div className="flex items-start gap-3">
          <Controller
            control={form.control}
            name="auto_analyze"
            render={({ field }) => (
              <Switch
                id="auto_analyze"
                checked={field.value}
                onCheckedChange={field.onChange}
                disabled
              />
            )}
          />
          <div className="grid gap-0.5">
            <Label htmlFor="auto_analyze" className="font-normal">
              Analyze immediately
            </Label>
            <p className="text-sm text-muted-foreground">
              Available once the analysis pipeline ships in Phase 4.
            </p>
          </div>
        </div>

        {uploading ? (
          <div className="grid gap-2" aria-live="polite">
            <Progress value={Math.round(progress * 100)} aria-label="Upload progress" />
            <p className="text-sm text-muted-foreground">
              {progress < 1 ? `Uploading… ${Math.round(progress * 100)}%` : "Processing the image…"}
            </p>
          </div>
        ) : (
          <Button type="submit" size="lg">
            Upload chart
          </Button>
        )}
      </div>
    </form>
  );
}
