<?php

declare(strict_types=1);

namespace App\Domain\Prediction\Services;

use App\Domain\Market\Models\PriceBar;
use App\Domain\Prediction\Enums\EvaluationMethod;
use App\Domain\Prediction\Enums\OutcomeResult;
use App\Domain\Prediction\Enums\PredictionKind;
use App\Domain\Prediction\Enums\PredictionStatus;
use App\Domain\Prediction\Enums\TradeDirection;
use App\Domain\Prediction\Enums\TriggerType;
use App\Domain\Prediction\Models\Prediction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Scores a prediction against what price actually did (P6-01, docs/04 §10).
 *
 * Pure: it takes a prediction and the bars that came after it and returns the outcome. No queries,
 * no clock, no writes — so every rule below is testable on a fixed table of bars.
 *
 * The guards against self-deception are the point:
 * - only bars strictly after `as_of` count, so an analysis can never be scored on data it saw;
 * - a bar that touches both the stop and the target counts as a loss, because we cannot know which
 *   came first inside the bar;
 * - a gap through the stop fills at the open, not at the stop, so slippage is not wished away.
 */
final readonly class PredictionEvaluator
{
    /**
     * @param  Collection<int, PriceBar>  $bars  strictly after `as_of`, ascending
     */
    public function evaluate(Prediction $prediction, Collection $bars): EvaluationResult
    {
        $bars = $bars->filter(fn (PriceBar $bar): bool => $bar->bar_time->greaterThan($prediction->as_of))
            ->sortBy(fn (PriceBar $bar): string => $bar->bar_time->toIso8601String())
            ->values();

        if ($bars->isEmpty()) {
            return EvaluationResult::unchanged($prediction);
        }

        return $prediction->kind === PredictionKind::DirectionalBias
            ? $this->evaluateBias($prediction, $bars)
            : $this->evaluateSetup($prediction, $bars);
    }

    /**
     * A directional call: was the close above (or below) the reference price at the horizon?
     *
     * @param  Collection<int, PriceBar>  $bars
     */
    private function evaluateBias(Prediction $prediction, Collection $bars): EvaluationResult
    {
        $horizon = $prediction->horizon_bars;
        if ($bars->count() < $horizon) {
            return EvaluationResult::unchanged($prediction, $bars->last()?->bar_time);
        }

        /** @var PriceBar $final */
        $final = $bars->get($horizon - 1);
        $close = (float) $final->close;
        $reference = (float) $prediction->reference_price;
        $up = $close > $reference;

        $correct = match ($prediction->direction) {
            TradeDirection::Long => $up,
            TradeDirection::Short => ! $up,
            TradeDirection::Flat => abs($close - $reference) / $reference < 0.02,
        };

        // Brier score: the setup score read as a probability of the bias being right.
        $probability = $prediction->setup_score !== null ? $prediction->setup_score / 100 : 0.5;
        $brier = ($probability - ($correct ? 1.0 : 0.0)) ** 2;

        return new EvaluationResult(
            status: PredictionStatus::Expired,
            result: $correct ? OutcomeResult::Win : OutcomeResult::Loss,
            method: EvaluationMethod::Auto,
            resolvedAt: $final->bar_time,
            evaluatedThrough: $final->bar_time,
            exitPrice: $close,
            barsHeld: $horizon,
            returnPct: round(($close - $reference) / $reference * 100, 4),
            directionCorrect: $correct,
            brierScore: round($brier, 5),
        );
    }

    /**
     * A trade setup: trigger, then stop or target, then expiry.
     *
     * @param  Collection<int, PriceBar>  $bars
     */
    private function evaluateSetup(Prediction $prediction, Collection $bars): EvaluationResult
    {
        $long = $prediction->direction !== TradeDirection::Short;
        $stop = $this->number($prediction->stop_price);
        $target = $this->number($prediction->target_price_1);
        $invalidation = $this->number($prediction->invalidation_price);

        $active = $prediction->status === PredictionStatus::Active;
        $fill = $active ? $this->number($prediction->entry_price) : null;
        $activatedAt = $prediction->activated_at;
        $mfe = 0.0;
        $mae = 0.0;
        $barsHeld = 0;
        $last = null;

        foreach ($bars as $bar) {
            $last = $bar;
            $high = (float) $bar->high;
            $low = (float) $bar->low;
            $open = (float) $bar->open;
            $close = (float) $bar->close;

            if ($fill === null) {
                // Invalidation cancels a setup that never triggered.
                if ($invalidation !== null && $this->breached($close, $invalidation, $long)) {
                    return new EvaluationResult(
                        status: PredictionStatus::Invalidated,
                        result: OutcomeResult::Invalidated,
                        method: EvaluationMethod::Auto,
                        resolvedAt: $bar->bar_time,
                        evaluatedThrough: $bar->bar_time,
                        barsHeld: $barsHeld,
                    );
                }

                $fill = $this->triggerFill($prediction, $bar, $long);
                if ($fill !== null) {
                    $activatedAt = $bar->bar_time;
                    $mfe = 0.0;
                    $mae = 0.0;
                }
                if ($fill === null) {
                    $barsHeld++;
                    if ($barsHeld >= $prediction->horizon_bars) {
                        return new EvaluationResult(
                            status: PredictionStatus::Expired,
                            result: OutcomeResult::Expired,
                            method: EvaluationMethod::Auto,
                            resolvedAt: $bar->bar_time,
                            evaluatedThrough: $bar->bar_time,
                            barsHeld: $barsHeld,
                        );
                    }

                    continue;
                }
            }

            $barsHeld++;
            $mfe = max($mfe, $long ? $high - $fill : $fill - $low);
            $mae = max($mae, $long ? $fill - $low : $high - $fill);

            // A gap through the stop fills at the open: the stop was not available at its price.
            if ($stop !== null && ($long ? $open <= $stop : $open >= $stop)) {
                return $this->stopped($prediction, $bar, $fill, $open, $barsHeld, $mfe, $mae, $activatedAt, false);
            }

            $hitStop = $stop !== null && ($long ? $low <= $stop : $high >= $stop);
            $hitTarget = $target !== null && ($long ? $high >= $target : $low <= $target);

            if ($hitStop && $hitTarget) {
                // Both inside one bar: we cannot know the order, so it counts as the loss.
                return $this->stopped($prediction, $bar, $fill, $stop, $barsHeld, $mfe, $mae, $activatedAt, true);
            }
            if ($hitStop) {
                return $this->stopped($prediction, $bar, $fill, $stop, $barsHeld, $mfe, $mae, $activatedAt, false);
            }
            if ($hitTarget) {
                return new EvaluationResult(
                    status: PredictionStatus::TargetHit,
                    result: OutcomeResult::Win,
                    method: EvaluationMethod::Auto,
                    resolvedAt: $bar->bar_time,
                    evaluatedThrough: $bar->bar_time,
                    activatedAt: $activatedAt,
                    entryFill: $fill,
                    exitPrice: $target,
                    barsHeld: $barsHeld,
                    rMultiple: $this->rMultiple($fill, $target, $stop, $long),
                    returnPct: $this->returnPct($fill, $target, $long),
                    mfePct: $this->asPercent($mfe, $fill),
                    maePct: $this->asPercent($mae, $fill),
                );
            }

            if ($barsHeld >= $prediction->horizon_bars) {
                // Out of time while still open: mark to market rather than pretend it is unresolved.
                return new EvaluationResult(
                    status: PredictionStatus::Expired,
                    result: OutcomeResult::Expired,
                    method: EvaluationMethod::Auto,
                    resolvedAt: $bar->bar_time,
                    evaluatedThrough: $bar->bar_time,
                    activatedAt: $activatedAt,
                    entryFill: $fill,
                    exitPrice: $close,
                    barsHeld: $barsHeld,
                    rMultiple: $this->rMultiple($fill, $close, $stop, $long),
                    returnPct: $this->returnPct($fill, $close, $long),
                    mfePct: $this->asPercent($mfe, $fill),
                    maePct: $this->asPercent($mae, $fill),
                );
            }
        }

        // Ran out of bars before anything resolved: record progress, stay open.
        return new EvaluationResult(
            status: $fill === null ? PredictionStatus::Pending : PredictionStatus::Active,
            result: null,
            method: EvaluationMethod::Auto,
            evaluatedThrough: $last?->bar_time,
            activatedAt: $activatedAt,
            entryFill: $fill,
            barsHeld: $barsHeld,
            mfePct: $fill === null ? null : $this->asPercent($mfe, $fill),
            maePct: $fill === null ? null : $this->asPercent($mae, $fill),
        );
    }

    /**
     * The fill price if this bar triggers the entry, or null.
     */
    private function triggerFill(Prediction $prediction, PriceBar $bar, bool $long): ?float
    {
        $trigger = $this->number($prediction->trigger_price);
        $entry = $this->number($prediction->entry_price);

        return match ($prediction->trigger_type) {
            // An immediate setup is already active; it never waits for a trigger.
            TriggerType::Immediate => $entry ?? (float) $bar->open,
            TriggerType::CloseAbove => $trigger !== null && (float) $bar->close > $trigger
                ? (float) $bar->close
                : null,
            TriggerType::CloseBelow => $trigger !== null && (float) $bar->close < $trigger
                ? (float) $bar->close
                : null,
            // A touch fills at the trigger, unless the bar opened through it.
            TriggerType::Touch => $trigger !== null && $this->touched($bar, $trigger, $long)
                ? ($long ? min($trigger, (float) $bar->open) : max($trigger, (float) $bar->open))
                : null,
        };
    }

    private function touched(PriceBar $bar, float $trigger, bool $long): bool
    {
        return $long ? (float) $bar->low <= $trigger : (float) $bar->high >= $trigger;
    }

    private function breached(float $close, float $level, bool $long): bool
    {
        return $long ? $close < $level : $close > $level;
    }

    private function stopped(
        Prediction $prediction,
        PriceBar $bar,
        float $fill,
        float $exit,
        int $barsHeld,
        float $mfe,
        float $mae,
        ?CarbonImmutable $activatedAt,
        bool $ambiguous,
    ): EvaluationResult {
        return new EvaluationResult(
            status: PredictionStatus::Stopped,
            result: OutcomeResult::Loss,
            method: EvaluationMethod::Auto,
            resolvedAt: $bar->bar_time,
            evaluatedThrough: $bar->bar_time,
            activatedAt: $activatedAt,
            entryFill: $fill,
            exitPrice: $exit,
            barsHeld: $barsHeld,
            rMultiple: $this->rMultiple($fill, $exit, $this->number($prediction->stop_price),
                $prediction->direction !== TradeDirection::Short),
            returnPct: $this->returnPct($fill, $exit, $prediction->direction !== TradeDirection::Short),
            mfePct: $this->asPercent($mfe, $fill),
            maePct: $this->asPercent($mae, $fill),
            ambiguousBar: $ambiguous,
        );
    }

    private function rMultiple(float $fill, float $exit, ?float $stop, bool $long): ?float
    {
        if ($stop === null) {
            return null;
        }
        $risk = $long ? $fill - $stop : $stop - $fill;
        if ($risk <= 0) {
            return null;
        }

        return round((($long ? $exit - $fill : $fill - $exit)) / $risk, 2);
    }

    private function returnPct(float $fill, float $exit, bool $long): ?float
    {
        if ($fill <= 0) {
            return null;
        }

        return round((($long ? $exit - $fill : $fill - $exit)) / $fill * 100, 4);
    }

    private function asPercent(float $excursion, float $fill): ?float
    {
        return $fill > 0 ? round($excursion / $fill * 100, 4) : null;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
