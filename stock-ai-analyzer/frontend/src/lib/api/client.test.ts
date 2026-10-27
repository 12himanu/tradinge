import { describe, expect, it } from "vitest";

import { toApiError } from "./client";

describe("toApiError", () => {
  it("reads the API error envelope", () => {
    const error = toApiError(
      413,
      {
        error: {
          code: "FILE_TOO_LARGE",
          message: "The image is 12 MB.",
          details: { max_bytes: 10 },
        },
      },
      "fallback",
    );

    expect(error).toMatchObject({
      status: 413,
      code: "FILE_TOO_LARGE",
      message: "The image is 12 MB.",
    });
    expect(error.details).toEqual({ max_bytes: 10 });
  });

  it("maps Laravel validation errors to field errors", () => {
    const error = toApiError(
      422,
      { message: "Invalid.", errors: { symbol: ["Bad symbol."] } },
      "fallback",
    );

    expect(error.code).toBe("VALIDATION_FAILED");
    expect(error.fieldErrors).toEqual({ symbol: ["Bad symbol."] });
  });

  it("falls back to the status when the body isn't JSON", () => {
    expect(toApiError(413, null, "Payload Too Large")).toMatchObject({
      code: "PAYLOAD_TOO_LARGE",
      message: "Payload Too Large",
    });
    expect(toApiError(502, null, "Bad Gateway").code).toBe("SERVER_ERROR");
  });
});
