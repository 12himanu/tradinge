import { describe, expect, it } from "vitest";

import {
  normalizeSymbol,
  toFormData,
  uploadFormSchema,
  validateDimensions,
  validateImageFile,
  type UploadFormValues,
} from "./schema";

const valid: UploadFormValues = {
  symbol: "nabil",
  exchange: "NEPSE",
  timeframe: "1D",
  trading_style: "swing",
  position_status: "none",
  position_entry_price: "",
  position_quantity: "",
  thesis: "",
  auto_analyze: false,
};

function fieldErrors(values: UploadFormValues): string[] {
  const result = uploadFormSchema.safeParse(values);
  return result.success ? [] : result.error.issues.map((issue) => issue.path.join("."));
}

describe("uploadFormSchema", () => {
  it("accepts a typical upload", () => {
    expect(fieldErrors(valid)).toEqual([]);
  });

  it("requires entry price and quantity only when holding", () => {
    expect(fieldErrors({ ...valid, position_status: "holding" })).toEqual([
      "position_entry_price",
      "position_quantity",
    ]);
    expect(
      fieldErrors({
        ...valid,
        position_status: "holding",
        position_entry_price: "552.5",
        position_quantity: "100",
      }),
    ).toEqual([]);
    expect(
      fieldErrors({
        ...valid,
        position_status: "holding",
        position_entry_price: "0",
        position_quantity: "-1",
      }),
    ).toHaveLength(2);
  });

  it("accepts symbols the server accepts and rejects the rest", () => {
    expect(fieldErrors({ ...valid, symbol: " n abil " })).toEqual([]);
    expect(fieldErrors({ ...valid, symbol: "" })).toEqual([]);
    expect(fieldErrors({ ...valid, symbol: "NEPSE:HDL" })).toEqual(["symbol"]);
    expect(fieldErrors({ ...valid, symbol: "X".repeat(21) })).toEqual(["symbol"]);
  });

  it("limits the thesis to 2000 characters", () => {
    expect(fieldErrors({ ...valid, thesis: "x".repeat(2001) })).toEqual(["thesis"]);
  });
});

describe("image checks", () => {
  it("accepts PNG, JPEG and WebP up to 10 MB", () => {
    expect(validateImageFile(new File(["x"], "c.png", { type: "image/png" }))).toBeNull();
    expect(validateImageFile(new File(["x"], "c.gif", { type: "image/gif" }))).toMatch(
      /PNG, JPEG and WebP/,
    );
    expect(
      validateImageFile(
        new File([new Uint8Array(10 * 1024 * 1024 + 1)], "c.png", { type: "image/png" }),
      ),
    ).toMatch(/10 MB or smaller/);
  });

  it("enforces the pixel bounds", () => {
    expect(validateDimensions(1200, 700)).toBeNull();
    expect(validateDimensions(300, 200)).toMatch(/300×200 px, too small/);
    expect(validateDimensions(9000, 700)).toMatch(/maximum/);
  });
});

describe("toFormData", () => {
  it("normalizes the symbol and omits empty and irrelevant fields", () => {
    const form = toFormData(new File(["x"], "c.png", { type: "image/png" }), {
      ...valid,
      symbol: " hdl ",
      timeframe: "detect",
      position_entry_price: "552",
    });

    expect(form.get("symbol")).toBe("HDL");
    expect(form.has("timeframe")).toBe(false);
    expect(form.has("thesis")).toBe(false);
    expect(form.has("position_entry_price")).toBe(false);
    expect(form.get("auto_analyze")).toBe("0");
    expect(form.get("image")).toBeInstanceOf(File);
  });
});

describe("normalizeSymbol", () => {
  it("uppercases and strips whitespace like the server", () => {
    expect(normalizeSymbol(" n abil\t")).toBe("NABIL");
  });
});
