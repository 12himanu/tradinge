import { renderHook } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { imageFromClipboard, usePasteImage } from "./use-paste-image";

type FakeItem = { kind: string; type: string; getAsFile: () => File | null };

function clipboard(items: FakeItem[]): DataTransfer {
  return { items } as unknown as DataTransfer;
}

function imageItem(type = "image/png"): FakeItem {
  const file = new File(["png-bytes"], "image.png", { type });
  return { kind: "file", type, getAsFile: () => file };
}

const textItem: FakeItem = { kind: "string", type: "text/plain", getAsFile: () => null };

function pasteEvent(data: DataTransfer): Event {
  const event = new Event("paste", { cancelable: true });
  Object.defineProperty(event, "clipboardData", { value: data });
  return event;
}

describe("imageFromClipboard", () => {
  it("returns the pasted image with a descriptive name", () => {
    const file = imageFromClipboard(
      clipboard([textItem, imageItem()]),
      new Date("2026-09-13T08:15:02Z"),
    );

    expect(file?.name).toBe("screenshot-20260913-081502.png");
    expect(file?.type).toBe("image/png");
  });

  it("ignores text-only pastes", () => {
    expect(imageFromClipboard(clipboard([textItem]))).toBeNull();
    expect(imageFromClipboard(null)).toBeNull();
  });
});

describe("usePasteImage", () => {
  it("hands pasted images to the callback and cancels the default paste", () => {
    const onImage = vi.fn();
    renderHook(() => usePasteImage(onImage));

    const event = pasteEvent(clipboard([imageItem("image/jpeg")]));
    window.dispatchEvent(event);

    expect(onImage).toHaveBeenCalledOnce();
    expect(onImage.mock.calls[0][0].name).toMatch(/\.jpeg$/);
    expect(event.defaultPrevented).toBe(true);
  });

  it("leaves text pastes alone and stops listening when disabled", () => {
    const onImage = vi.fn();
    const { rerender } = renderHook(({ enabled }) => usePasteImage(onImage, enabled), {
      initialProps: { enabled: true },
    });

    const textPaste = pasteEvent(clipboard([textItem]));
    window.dispatchEvent(textPaste);
    expect(textPaste.defaultPrevented).toBe(false);

    rerender({ enabled: false });
    window.dispatchEvent(pasteEvent(clipboard([imageItem()])));

    expect(onImage).not.toHaveBeenCalled();
  });
});
