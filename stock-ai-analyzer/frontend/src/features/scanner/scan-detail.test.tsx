import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { MarketScan, ScanPlan } from "@/lib/api/types";

import { ScanDetail } from "./scan-detail";

/**
 * A trade plan copied verbatim from `market_scans.plans` in a real scan.
 *
 * Copied rather than written, on purpose. The drawer once crashed on `plan.kind` — a field the
 * engine has never emitted — and TypeScript could not catch it because the hand-written type
 * declared that field too. A fixture invented from memory would have reproduced the same lie, so
 * this one is the wire shape.
 */
const REAL_PLAN: ScanPlan = {
  rr_net: 0.9,
  viable: false,
  reasons: ["Net R:R 0.90 is below the 2.0 minimum for this style"],
  targets: [204, 209],
  quantity: 120,
  risk_pct: 2.53,
  rr_gross: 1.62,
  direction: "long",
  entry_type: "pullback",
  reward_pct: 4.08,
  stop_basis: "below the support zone, minus the style buffer",
  stop_price: 191.0464,
  entry_price: 196,
  evidence_id: "E47",
  trigger_met: false,
  position_pct: 23.52,
  trigger_type: "touch",
  trigger_price: 196,
  position_value: 23520,
  viable_at_price: 193.5258,
};

function scan(overrides: Partial<MarketScan> = {}): MarketScan {
  return {
    id: 1,
    stock: { id: 2, symbol: "PCBL", name: "Prime Commercial Bank Ltd.", sector: "Commercial Banks" },
    scanned_on: "2026-09-18",
    as_of: "2026-09-18T00:00:00Z",
    timeframe: "1D",
    trading_style: "swing",
    last_price: 259,
    bias: "bullish",
    setup_score: 83,
    decision: "wait",
    decision_rationale: "Good chart, poor price.",
    structure: "bullish",
    last_event: "choch_bullish",
    range_zone: "premium",
    range_position: 1,
    trap_count: 1,
    opportunity_count: 1,
    has_viable_plan: false,
    bar_count: 250,
    data_quality: 1,
    engine_version: "0.1.0+test",
    error_code: null,
    error_message: null,
    calibrated_probability: null,
    signals: [],
    base_rates: [],
    levels: [],
    plans: [REAL_PLAN],
    factors: [],
    warnings: [],
    report_markdown: null,
    ...overrides,
  };
}

vi.mock("./queries", () => ({
  useStockScan: () => ({ data: mocked, isPending: false, isError: false, error: null }),
}));

let mocked: MarketScan | undefined;

function renderDetail(data: MarketScan) {
  mocked = data;
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <ScanDetail stockId={2} symbol="PCBL" onClose={() => {}} />
    </QueryClientProvider>,
  );
}

describe("ScanDetail trade plans", () => {
  it("renders a real engine plan without throwing", () => {
    renderDetail(scan());

    expect(screen.getByText("Pullback")).toBeInTheDocument();
    expect(screen.getByText("Not viable")).toBeInTheDocument();
  });

  it("shows every target, not just the first", () => {
    renderDetail(scan());

    expect(screen.getByText("204.00 · 209.00")).toBeInTheDocument();
  });

  it("leads with the net risk-reward, because that is what the decision gate reads", () => {
    renderDetail(scan());

    expect(screen.getByText("0.90 (1.62 gross)")).toBeInTheDocument();
  });

  it("says why an unviable plan is unviable", () => {
    renderDetail(scan());

    expect(
      screen.getByText("Net R:R 0.90 is below the 2.0 minimum for this style"),
    ).toBeInTheDocument();
    expect(screen.getByText(/Would be viable at 193\.53/)).toBeInTheDocument();
  });

  it("renders a scan that carries no plans at all", () => {
    renderDetail(scan({ plans: [] }));

    expect(screen.queryByText("Trade plans")).not.toBeInTheDocument();
  });
});
