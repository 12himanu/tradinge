/**
 * Client for the Laravel API (docs/05-api-spec.md §1).
 *
 * Requests go to same-origin /api/v1 and /sanctum, which Next rewrites to the backend
 * (next.config.ts), so the Sanctum session and XSRF cookies are first-party. Mutating requests carry
 * the XSRF token; an expired token (419) is refreshed once and the request retried. Every failure is
 * normalized to an ApiError with a `code` and `message`.
 */

export type FieldErrors = Record<string, string[]>;

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly fieldErrors: FieldErrors = {},
    readonly details: Record<string, unknown> = {},
  ) {
    super(message);
    this.name = "ApiError";
  }
}

export const API_BASE = "/api/v1";

const MUTATING = new Set(["POST", "PUT", "PATCH", "DELETE"]);

const STATUS_CODES: Record<number, string> = {
  401: "UNAUTHENTICATED",
  403: "FORBIDDEN",
  404: "NOT_FOUND",
  413: "PAYLOAD_TOO_LARGE",
  419: "CSRF_TOKEN_MISMATCH",
  429: "TOO_MANY_REQUESTS",
  503: "SERVICE_UNAVAILABLE",
};

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function readCookie(name: string): string | null {
  const prefix = `${name}=`;
  const cookie = document.cookie.split("; ").find((entry) => entry.startsWith(prefix));
  return cookie ? decodeURIComponent(cookie.slice(prefix.length)) : null;
}

export async function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readCookie("XSRF-TOKEN")) return;
  await fetch("/sanctum/csrf-cookie", {
    credentials: "include",
    headers: { Accept: "application/json" },
  });
}

function requestHeaders(method: string, json: boolean): Record<string, string> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  };
  if (json) headers["Content-Type"] = "application/json";
  if (MUTATING.has(method)) {
    const token = readCookie("XSRF-TOKEN");
    if (token) headers["X-XSRF-TOKEN"] = token;
  }
  return headers;
}

/** Maps any API error body — `{error: {code, message, details}}` or Laravel's 422 — to an ApiError. */
export function toApiError(status: number, body: unknown, fallback: string): ApiError {
  const code = STATUS_CODES[status] ?? (status >= 500 ? "SERVER_ERROR" : "HTTP_ERROR");

  if (isRecord(body) && isRecord(body.error)) {
    const { error } = body;
    return new ApiError(
      status,
      typeof error.code === "string" ? error.code : code,
      typeof error.message === "string" ? error.message : fallback,
      {},
      isRecord(error.details) ? error.details : {},
    );
  }

  if (isRecord(body) && isRecord(body.errors)) {
    const fieldErrors: FieldErrors = {};
    for (const [field, messages] of Object.entries(body.errors)) {
      fieldErrors[field] = Array.isArray(messages) ? messages.map(String) : [String(messages)];
    }
    const message =
      typeof body.message === "string" ? body.message : "Please check the highlighted fields.";
    return new ApiError(status, "VALIDATION_FAILED", message, fieldErrors);
  }

  if (isRecord(body) && typeof body.message === "string" && body.message !== "") {
    return new ApiError(status, code, body.message);
  }

  return new ApiError(status, code, fallback);
}

const NETWORK_ERROR_MESSAGE = "Can't reach the server. Check that the Docker stack is running.";

type RequestOptions = {
  method?: string;
  json?: unknown;
  form?: FormData;
  signal?: AbortSignal;
};

export async function apiFetch<T>(
  path: string,
  options: RequestOptions = {},
  retried = false,
): Promise<T> {
  const hasBody = options.json !== undefined || options.form !== undefined;
  const method = (options.method ?? (hasBody ? "POST" : "GET")).toUpperCase();

  if (MUTATING.has(method)) await ensureCsrfCookie();

  let response: Response;
  try {
    response = await fetch(`${API_BASE}${path}`, {
      method,
      credentials: "include",
      signal: options.signal,
      headers: requestHeaders(method, options.json !== undefined),
      body: options.form ?? (options.json !== undefined ? JSON.stringify(options.json) : undefined),
    });
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") throw error;
    throw new ApiError(0, "NETWORK_ERROR", NETWORK_ERROR_MESSAGE);
  }

  if (response.status === 419 && !retried) {
    await ensureCsrfCookie(true);
    return apiFetch<T>(path, options, true);
  }
  if (response.status === 204) return undefined as T;

  const body: unknown = await response.json().catch(() => null);
  if (!response.ok)
    throw toApiError(response.status, body, response.statusText || "Request failed.");

  return body as T;
}

type UploadOptions = {
  onProgress?: (fraction: number) => void;
  signal?: AbortSignal;
};

/** Multipart POST with upload progress (fetch can't report it, so this uses XMLHttpRequest). */
export async function apiUpload<T>(
  path: string,
  form: FormData,
  options: UploadOptions = {},
  retried = false,
): Promise<T> {
  await ensureCsrfCookie();

  const { status, body } = await new Promise<{ status: number; body: unknown }>(
    (resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open("POST", `${API_BASE}${path}`);
      xhr.withCredentials = true;
      xhr.responseType = "json";
      for (const [name, value] of Object.entries(requestHeaders("POST", false))) {
        xhr.setRequestHeader(name, value);
      }
      xhr.upload.onprogress = (event) => {
        if (event.lengthComputable) options.onProgress?.(event.loaded / event.total);
      };
      xhr.onload = () => resolve({ status: xhr.status, body: xhr.response });
      xhr.onerror = () => reject(new ApiError(0, "NETWORK_ERROR", NETWORK_ERROR_MESSAGE));
      xhr.onabort = () => reject(new DOMException("Upload cancelled.", "AbortError"));
      options.signal?.addEventListener("abort", () => xhr.abort(), { once: true });
      xhr.send(form);
    },
  );

  if (status === 419 && !retried) {
    await ensureCsrfCookie(true);
    return apiUpload<T>(path, form, options, true);
  }
  if (status < 200 || status >= 300) throw toApiError(status, body, "Upload failed.");

  return body as T;
}
