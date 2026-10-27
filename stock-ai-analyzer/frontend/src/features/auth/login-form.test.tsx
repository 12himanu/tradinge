import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError, apiFetch } from "@/lib/api/client";

import { LoginForm, safeNextPath } from "./login-form";

const replace = vi.fn();
let search = "";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace }),
  useSearchParams: () => new URLSearchParams(search),
}));

vi.mock("@/lib/api/client", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api/client")>()),
  apiFetch: vi.fn(),
  ensureCsrfCookie: vi.fn(),
}));

function renderForm() {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<LoginForm />, { wrapper });
}

beforeEach(() => {
  vi.mocked(apiFetch).mockReset();
  replace.mockReset();
  search = "";
});

describe("LoginForm", () => {
  it("validates before calling the API", async () => {
    renderForm();

    await userEvent.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByText("Enter a valid email address.")).toBeInTheDocument();
    expect(screen.getByText("Enter your password.")).toBeInTheDocument();
    expect(apiFetch).not.toHaveBeenCalled();
  });

  it("signs in and returns to the page the user came from", async () => {
    search = "next=%2Fmarket-data";
    vi.mocked(apiFetch).mockResolvedValue({
      data: { id: 1, name: "Owner", email: "owner@example.test" },
    });
    renderForm();

    await userEvent.type(screen.getByLabelText("Email"), "owner@example.test");
    await userEvent.type(screen.getByLabelText("Password"), "correct-horse-battery");
    await userEvent.click(screen.getByRole("button", { name: "Sign in" }));

    expect(apiFetch).toHaveBeenCalledWith("/auth/login", {
      json: { email: "owner@example.test", password: "correct-horse-battery", remember: true },
    });
    expect(replace).toHaveBeenCalledWith("/market-data");
  });

  it("shows the server's error on the email field", async () => {
    vi.mocked(apiFetch).mockRejectedValue(
      new ApiError(422, "VALIDATION_FAILED", "Invalid", {
        email: ["These credentials do not match our records."],
      }),
    );
    renderForm();

    await userEvent.type(screen.getByLabelText("Email"), "owner@example.test");
    await userEvent.type(screen.getByLabelText("Password"), "wrong");
    await userEvent.click(screen.getByRole("button", { name: "Sign in" }));

    expect(
      await screen.findByText("These credentials do not match our records."),
    ).toBeInTheDocument();
    expect(screen.getByLabelText("Email")).toHaveAttribute("aria-invalid", "true");
    expect(replace).not.toHaveBeenCalled();
  });

  it("shows non-field errors such as throttling in an alert", async () => {
    vi.mocked(apiFetch).mockRejectedValue(
      new ApiError(429, "TOO_MANY_REQUESTS", "Too Many Attempts."),
    );
    renderForm();

    await userEvent.type(screen.getByLabelText("Email"), "owner@example.test");
    await userEvent.type(screen.getByLabelText("Password"), "wrong");
    await userEvent.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("Too Many Attempts.");
  });
});

describe("safeNextPath", () => {
  it("only allows same-app paths", () => {
    expect(safeNextPath("/analyze")).toBe("/analyze");
    expect(safeNextPath("//evil.example")).toBe("/dashboard");
    expect(safeNextPath("https://evil.example")).toBe("/dashboard");
    expect(safeNextPath(null)).toBe("/dashboard");
  });
});
