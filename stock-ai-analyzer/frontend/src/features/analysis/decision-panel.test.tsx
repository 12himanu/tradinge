import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { Analysis } from "@/lib/api/types";

import { DecisionPanel } from "./decision-panel";
import { FactorList } from "./scenarios-and-factors";
import { TradePlanCard } from "./trade-plan-card";

function analysis(overrides: Partial<Analysis> = {}): Analysis {
  return {
    id: "01ANALYSIS",
    status: "completed",
    current_stage: null,
    chart_upload_id: "01CHART",
    stock: null,
    timeframe: "1D",
    trading_style: "swing",
    position_status: "none",
    data_tier: "ohlcv",
    data_quality: 0.87,
    as_of: "2026-09-10T00:00:00Z",
    price_bar_count: 120,
    last_price: 552.1,
    bias: "bullish",
    setup_score: 72,
    calibrated_probability: null,
    decision: "wait",
    decision_limited: false,
    narrative_source: "template",
    engine_version: "0.1.0+cfg.abc",
    error_code: null,
    error_message: null,
    duration_ms: 1200,
    queued_at: null,
    completed_at: null,
    created_at: null,
    user_thesis: null,
    report: {
      decision: {
        action: "wait",
        rule_id: 7,
        conditions: ["Daily close above 580.00"],
        invalidation: 520,
        limited: false,
        rationale: "Setup score 72 with a viable plan that has not triggered yet.",
      },
    },
    report_markdown: "# Report",
    warnings: [],
    evidence: [],
    score: null,
    smc: null,
    timings: [],
    indicators: [],
    patterns: [],
    levels: [],
    predictions: [],
    ...overrides,
  };
}

describe("DecisionPanel", () => {
  it("shows the decision, the score and its conditions", () => {
    render(<DecisionPanel analysis={analysis()} />);

    expect(screen.getByText("Wait")).toBeInTheDocument();
    expect(screen.getByText("72")).toBeInTheDocument();
    expect(screen.getByText("Daily close above 580.00")).toBeInTheDocument();
  });

  it("labels the score uncalibrated until outcomes exist", () => {
    render(<DecisionPanel analysis={analysis()} />);

    expect(screen.getByText("Uncalibrated")).toBeInTheDocument();
  });

  it("claims no score at all in a limited analysis", () => {
    render(
      <DecisionPanel
        analysis={analysis({ setup_score: null, decision_limited: true, data_tier: "vision_only" })}
      />,
    );

    expect(screen.getByText(/No setup score at this tier/)).toBeInTheDocument();
    expect(screen.getByText("Limited")).toBeInTheDocument();
  });

  it("pairs direction with a glyph, never colour alone", () => {
    render(<DecisionPanel analysis={analysis({ bias: "bearish" })} />);

    expect(screen.getByText(/Bearish bias/)).toBeInTheDocument();
    expect(screen.getByText("▼")).toBeInTheDocument();
  });
});

describe("TradePlanCard", () => {
  const plan = {
    direction: "long" as const,
    entry_type: "breakout" as const,
    entry_price: 560,
    trigger_type: "close_above" as const,
    trigger_price: 558,
    stop_price: 520,
    targets: [620, 650],
    risk_pct: 7.14,
    reward_pct: 10.71,
    rr_gross: 1.5,
    rr_net: 1.1,
    quantity: 100,
    position_value: 56000,
    position_pct: 11.2,
    viable: false,
    trigger_met: false,
    reasons: ["Net R:R 1.10 is below the 2.0 minimum for this style"],
    viable_at_price: 548,
    stop_basis: "below the nearest support zone",
    evidence_id: "E12",
  };

  it("shows a non-viable plan with the price that would fix it", () => {
    render(<TradePlanCard plan={plan} tier="ohlcv" />);

    expect(screen.getByText("Not viable")).toBeInTheDocument();
    expect(screen.getByText(/below the 2.0 minimum/)).toBeInTheDocument();
    expect(screen.getByText(/548/)).toBeInTheDocument();
  });

  it("marks Tier B numbers as approximate", () => {
    render(<TradePlanCard plan={{ ...plan, viable: true, reasons: [] }} tier="digitized" />);

    expect(screen.getAllByText(/≈/).length).toBeGreaterThan(0);
  });
});

describe("FactorList", () => {
  const factor = {
    key: "trend_ema_alignment",
    label: "EMA alignment",
    value: 1,
    weight: 0.15,
    effective_weight: 0.18,
    contribution: 0.18,
    available: true,
    reason: "6 of 6 EMA pairs in the bullish order",
    evidence_ids: ["E5"],
  };

  it("splits factors into bullish and bearish with their weights", () => {
    render(
      <FactorList
        factors={[
          factor,
          { ...factor, key: "pattern", label: "Chart pattern", contribution: -0.07 },
        ]}
      />,
    );

    expect(screen.getByText("EMA alignment")).toBeInTheDocument();
    expect(screen.getByText("(+0.18)")).toBeInTheDocument();
    expect(screen.getByText("(-0.07)")).toBeInTheDocument();
  });

  it("names the factors that were dropped for lack of data", () => {
    render(<FactorList factors={[{ ...factor, available: false, contribution: 0 }]} />);

    expect(screen.getByText(/Dropped for lack of data/)).toBeInTheDocument();
  });

  it("renders nothing rather than crashing when there is no score", () => {
    render(<FactorList factors={undefined} />);

    expect(screen.getAllByText("None.")).toHaveLength(2);
  });
});
