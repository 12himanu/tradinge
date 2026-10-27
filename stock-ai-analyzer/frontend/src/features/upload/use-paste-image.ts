"use client";

import { useEffect, useEffectEvent } from "react";

/** The first image file on the clipboard, renamed from the browser's generic "image.png". */
export function imageFromClipboard(data: DataTransfer | null, now: Date = new Date()): File | null {
  if (!data) return null;

  for (const item of Array.from(data.items)) {
    if (item.kind !== "file" || !item.type.startsWith("image/")) continue;

    const file = item.getAsFile();
    if (!file) continue;

    const extension = file.type.split("/")[1] ?? "png";
    const stamp = now.toISOString().slice(0, 19).replace(/[-:]/g, "").replace("T", "-");
    return new File([file], `screenshot-${stamp}.${extension}`, { type: file.type });
  }

  return null;
}

/**
 * Calls `onImage` when an image is pasted (Ctrl+V) anywhere on the page. Text pastes are left alone,
 * so pasting into the thesis field still works.
 */
export function usePasteImage(onImage: (file: File) => void, enabled = true): void {
  const handleImage = useEffectEvent(onImage);

  useEffect(() => {
    if (!enabled) return;

    function onPaste(event: ClipboardEvent) {
      const file = imageFromClipboard(event.clipboardData);
      if (!file) return;
      event.preventDefault();
      handleImage(file);
    }

    window.addEventListener("paste", onPaste);
    return () => window.removeEventListener("paste", onPaste);
  }, [enabled]);
}
