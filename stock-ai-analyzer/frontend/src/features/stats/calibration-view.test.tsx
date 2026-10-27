import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { Calibration, CalibrationBucket } from "@/lib/api/types";

import { CalibrationView } from "./calibration-view";

const getCalibration = vi.hoisted(() => vi.fn());
vi.mock("./api", () => ({ getCalibration }));

function bucket(overrides: Partial<CalibrationBucket> = {}): CalibrationBucket {
  const n = overrides.n ?? 30;
  const observed = overrides.observed ?? 0.6;
  return {
    bucket: "70-79",
    low: 70,
    high: 79,
    n,
    wins: Math.round(observed * n),
    predicted: 0.72,
    observed,
    interval: { low: 0.42, high: 0.75 },
    support: n,
    calibrated: n >= 30 ? observed : null,
    qualified: n >= 30,
    ...overrides,
  };
}

function curve(overrides: Partial<Calibration> = {}): Calibration {
  const buckets = overrides.buckets ?? [bucket()];
  return {
    engine_version: "0.1.0+cfg.abc",
    scope: "global",
    method: "binned",
    sample_size: buckets.reduce((sum, b) => sum + b.n, 0),
    active: buckets.some((b) => b.qualified),
    min_bucket_sample: 30,
    brier_score: 0.23,
    brier_score_calibrated: 0.21,
    calibration_error: 0.12,
    buckets,
    engine_versions: ["0.1.0+cfg.abc"],
    ...overrides,
  };
}

async function renderCurve(data: Calibration) {
  getCalibration.mockResolvedValue(data);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  render(
    <QueryClientProvider client={client}>
      <CalibrationView />
    </QueryClientProvider>,
  );

  return screen.findByText(/Is the score honest\?/);
}

describe("CalibrationView", () => {
  it("says uncalibrated and publishes nothing while a band is thin", async () => {
    await renderCurve(
      curve({
        buckets: [bucket({ n: 8, observed: 0.75 })],
        active: false,
      }),
    );

    expect(screen.getByText("Uncalibrated")).toBeInTheDocument();
    // The band's own rate is still shown — it is real data — but nothing is published from it.
    expect(screen.getByText("75%")).toBeInTheDocument();
    expect(screen.getAllByTitle("Needs 30 outcomes")).toHaveLength(1);
  });

  it("publishes the realised rate once a band qualifies", async () => {
    await renderCurve(curve());

    expect(screen.getByText("Calibration on")).toBeInTheDocument();
    // Claimed 72%, delivered 60%: both visible, so the gap is unmissable.
    expect(screen.getByText("72%")).toBeInTheDocument();
    expect(screen.getAllByText("60%").length).toBeGreaterThan(0);
  });

  it("explains an empty sample instead of drawing an empty chart", async () => {
    await renderCurve(curve({ buckets: [], sample_size: 0, active: false }));

    expect(screen.getByText(/No resolved setups for this engine version yet/)).toBeInTheDocument();
    expect(screen.queryByRole("img")).not.toBeInTheDocument();
  });

  it("describes the diagram for screen readers, including how many bands are live", async () => {
    await renderCurve(
      curve({
        buckets: [
          bucket({ bucket: "60-69", low: 60, high: 69, predicted: 0.65, observed: 0.7, n: 40 }),
          bucket({ n: 12, observed: 0.5 }),
        ],
      }),
    );

    const chart = screen.getByRole("img");
    expect(chart).toHaveAccessibleName(
      expect.stringContaining("52 resolved setups in 2 score bands"),
    );
    expect(chart).toHaveAccessibleName(expect.stringContaining("1 band(s) have reached 30"));
    // The 70-79 band delivered 50% against a claimed 72%.
    expect(chart).toHaveAccessibleName(
      expect.stringContaining("1 band(s) performed worse than their score claimed"),
    );
  });

  it("marks a band that borrowed strength from its neighbours", async () => {
    await renderCurve(
      curve({
        method: "isotonic",
        buckets: [bucket({ n: 12, support: 46, calibrated: 0.55, qualified: true })],
      }),
    );

    expect(
      screen.getByTitle("Pooled with neighbouring bands: 46 outcomes back this estimate"),
    ).toBeInTheDocument();
    expect(screen.getByText(/Fitted by isotonic regression/)).toBeInTheDocument();
  });
});
