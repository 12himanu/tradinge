"use client";

import { ImagePlus, X } from "lucide-react";
import { useDropzone } from "react-dropzone";

import { Button } from "@/components/ui/button";
import { formatBytes } from "@/lib/format";
import { cn } from "@/lib/utils";

export type SelectedImage = { file: File; previewUrl: string; width: number; height: number };

type Props = {
  image: SelectedImage | null;
  error: string | null;
  disabled?: boolean;
  onFile: (file: File) => void;
  onClear: () => void;
};

/** Drop target and file picker. Pasting is handled page-wide by usePasteImage. */
export function ChartDropzone({ image, error, disabled, onFile, onClear }: Props) {
  const { getRootProps, getInputProps, isDragActive, open } = useDropzone({
    accept: { "image/png": [], "image/jpeg": [], "image/webp": [] },
    multiple: false,
    noClick: image !== null,
    disabled,
    // Type and size are re-checked by the form (and the server), so accept rejections here too.
    onDrop: (accepted, rejected) => {
      const file = accepted[0] ?? rejected[0]?.file;
      if (file) onFile(file);
    },
  });

  return (
    <div className="grid gap-2">
      <div
        {...getRootProps({
          "aria-invalid": error ? true : undefined,
          "aria-describedby": error ? "image-error" : "image-hint",
        })}
        className={cn(
          "relative flex min-h-72 flex-col items-center justify-center overflow-hidden rounded-xl border-2 border-dashed p-6 text-center transition-colors",
          image ? "cursor-default border-solid bg-muted/30" : "cursor-pointer hover:bg-muted/40",
          isDragActive && "border-primary bg-muted/60",
          error && "border-destructive",
          disabled && "pointer-events-none opacity-60",
        )}
      >
        <input {...getInputProps({ "aria-label": "Chart screenshot" })} />

        {image ? (
          <>
            {/* eslint-disable-next-line @next/next/no-img-element -- local object URL preview */}
            <img
              src={image.previewUrl}
              alt="Selected chart"
              className="max-h-80 w-auto rounded-md object-contain"
            />
            <p className="mt-3 text-xs text-muted-foreground">
              {image.file.name} · {image.width}×{image.height} px · {formatBytes(image.file.size)}
            </p>
            <div className="mt-3 flex gap-2">
              <Button type="button" variant="outline" size="sm" onClick={open} disabled={disabled}>
                Replace
              </Button>
              <Button type="button" variant="ghost" size="sm" onClick={onClear} disabled={disabled}>
                <X aria-hidden />
                Remove
              </Button>
            </div>
          </>
        ) : (
          <>
            <ImagePlus className="size-10 text-muted-foreground" aria-hidden />
            <p className="mt-3 font-medium">
              {isDragActive ? "Drop the screenshot" : "Paste, drop or choose a chart"}
            </p>
            <p id="image-hint" className="mt-1 text-sm text-muted-foreground">
              Press <kbd className="rounded border bg-muted px-1 font-mono text-xs">Ctrl</kbd>+
              <kbd className="rounded border bg-muted px-1 font-mono text-xs">V</kbd> anywhere on
              this page. PNG, JPEG or WebP, up to 10 MB, at least 600×400 px.
            </p>
          </>
        )}
      </div>
      {error && (
        <p id="image-error" role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
    </div>
  );
}

/** Reads the image in the browser for a preview and its pixel size; null if it isn't decodable. */
export function loadImage(file: File): Promise<Omit<SelectedImage, "file"> | null> {
  const previewUrl = URL.createObjectURL(file);
  return new Promise((resolve) => {
    const image = new Image();
    image.onload = () =>
      resolve({ previewUrl, width: image.naturalWidth, height: image.naturalHeight });
    image.onerror = () => {
      URL.revokeObjectURL(previewUrl);
      resolve(null);
    };
    image.src = previewUrl;
  });
}
