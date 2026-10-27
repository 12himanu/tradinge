import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { Analysis } from "@/lib/api/types";

import { DataLimitNotice } from "./data-limit-notice";

// The symbol form navigates on success and queries the symbol list; neither belongs in this test.
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));

function renderNotice(value: Analysis) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <DataLimitNotice analysis={value} />
    </QueryClientProvider>,
  );
}

function analysis(overrides: Partial<Analysis> = {}): Analysis {
  return {
    data_tier: "vision_only",
    stock: { id: 2, symbol: "NABIL", name: "Nabil Bank Limited" },
    timeframe: "1D",
    price_bar_count: 0,
    decision: "wait",
    decision_limited: true,
    ...overrides,
  } as Analysis;
}

describe("DataLimitNotice", () => {
  it("says nothing when the analysis had real price data", () => {
    const { container } = renderNotice(analysis({ data_tier: "ohlcv", price_bar_count: 83 }));

    expect(container).toBeEmptyDOMElement();
  });

  it("points at the import page when the symbol has no bars", () => {
    renderNotice(analysis());

    expect(screen.getByText("No price data for NABIL yet")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Import price data" })).toHaveAttribute(
      "href",
      "/market-data",
    );
  });

  it("names the shortfall when there is some data but not enough", () => {
    renderNotice(analysis({ price_bar_count: 41 }));

    expect(screen.getByText("Only 41 bars for NABIL — 60 are needed")).toBeInTheDocument();
  });

  it("asks for a symbol first when the chart was never linked to one", () => {
    renderNotice(analysis({ stock: null, price_bar_count: null }));

    expect(screen.getByText("This chart isn't linked to a symbol")).toBeInTheDocument();
    // Importing data cannot help until the chart names a ticker.
    expect(screen.queryByRole("link", { name: "Import price data" })).not.toBeInTheDocument();
  });

  it("offers to link the symbol here rather than sending the user to re-upload", () => {
    // Re-uploading the same image cannot fix it: the sha256 dedupe returns the original row and
    // discards the symbol typed the second time.
    renderNotice(analysis({ stock: null, price_bar_count: null, chart_upload_id: "abc" }));

    expect(screen.getByLabelText("Which symbol is this chart?")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Link and run again" })).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "Upload again" })).not.toBeInTheDocument();
  });

  it("tells the user the fix is a forced re-run, not another upload", () => {
    renderNotice(analysis());

    expect(screen.getByText("Run it again")).toBeInTheDocument();
  });
});
