<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Analysis\Enums\AnalysisStatus;
use App\Domain\Analysis\Enums\DataTier;
use App\Domain\Analysis\Enums\Decision;
use App\Domain\Analysis\Enums\DetectionMethod;
use App\Domain\Analysis\Enums\IndicatorStatus;
use App\Domain\Analysis\Enums\LevelSource;
use App\Domain\Analysis\Enums\LevelType;
use App\Domain\Analysis\Enums\NarrativeSource;
use App\Domain\Analysis\Enums\PatternCategory;
use App\Domain\Analysis\Enums\PatternStatus;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Prediction\Enums\PredictionKind;
use App\Domain\Prediction\Enums\PredictionStatus;
use App\Domain\Prediction\Enums\Scenario;
use App\Domain\Prediction\Enums\TradeDirection;
use App\Domain\Prediction\Enums\TriggerType;
use App\Domain\Shared\Enums\Bias;
use Carbon\CarbonImmutable;

/**
 * Translates the engine's `AnalysisResult` into database rows (P4D-05).
 *
 * The engine speaks tiers A/B/C and its own vocabulary; the database uses the names in
 * docs/03-database-schema.md. Anything unrecognised is dropped rather than guessed at, because a
 * mis-mapped decision or tier would be a lie about what the analysis actually said.
 */
final readonly class EngineResultMapper
{
    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function analysisAttributes(array $result, int $durationMs): array
    {
        // An absent score must stay null, not become `{}`: a Tier C analysis makes no claim, and an
        // empty object reads as "there is a score" to every consumer.
        $score = is_array($result['score'] ?? null) ? $result['score'] : null;
        $decision = $this->arrayValue($result, 'decision');

        return [
            'status' => $this->status($result),
            'data_tier' => $this->tier($this->stringValue($result, 'tier')),
            'data_quality' => $this->floatValue($result, 'data_quality'),
            'as_of' => $this->dateValue($result, 'as_of'),
            'last_price' => $this->floatValue($result, 'last_price'),
            'bias' => Bias::tryFrom($this->stringValue($result, 'bias') ?? ''),
            'setup_score' => $this->intValue($result, 'setup_score'),
            'decision' => Decision::tryFrom($this->stringValue($decision, 'action') ?? ''),
            'decision_limited' => (bool) ($decision['limited'] ?? false),
            'narrative_source' => NarrativeSource::tryFrom($this->stringValue($result, 'narrative_source') ?? '')
                ?? NarrativeSource::Template,
            'report' => $this->report($result, $score, $decision),
            'report_markdown' => $this->stringValue($result, 'report_markdown'),
            'evidence' => [
                'items' => $result['evidence'] ?? [],
                'score' => $score,
                'timings' => $result['timings'] ?? [],
                'smc' => $result['smc'] ?? null,
            ],
            'warnings' => is_array($result['warnings'] ?? null) ? array_values($result['warnings']) : [],
            'engine_version' => $this->stringValue($result, 'engine_version'),
            'current_stage' => null,
            'error_code' => null,
            'error_message' => null,
            'completed_at' => CarbonImmutable::now(),
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * The structured report the UI renders. The analyst's prose sits alongside the injected facts
     * below, which stay exactly as the engine computed them: the LLM never supplies a number.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $score
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private function report(array $result, ?array $score, array $decision): array
    {
        return [
            'schema_version' => $this->stringValue($result, 'schema_version') ?? '1.0',
            'decision' => $decision,
            'bias' => [
                'direction' => $this->stringValue($result, 'bias'),
                'setup_score' => $this->intValue($result, 'setup_score'),
                'calibrated_probability' => null,
                'note' => 'uncalibrated',
            ],
            'factors' => $score['factors'] ?? [],
            // The measured next-day and next-week move distributions (docs/04 §5.8). Kept with the
            // report because it is a statement about this chart, and it is the only forward-looking
            // number in the payload that was measured rather than judged.
            'outlook' => $result['outlook'] ?? [],
            'scenarios' => $result['scenarios'] ?? [],
            'trade_plans' => $result['trade_plans'] ?? [],
            'smc' => $result['smc'] ?? null,
            // Phase 4C: the analyst's prose, present only when its draft passed every guardrail.
            // Null means the template is the narrative — the same facts, written by the engine.
            'narrative' => is_array($result['narrative'] ?? null) ? $result['narrative'] : null,
            'narrative_source' => $this->stringValue($result, 'narrative_source'),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    public function indicators(array $result): array
    {
        $rows = [];
        foreach ($this->listValue($result, 'indicators') as $indicator) {
            $key = $this->stringValue($indicator, 'key');
            $name = $this->stringValue($indicator, 'name');
            if ($key === null || $name === null) {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'name' => $name,
                'params' => is_array($indicator['params'] ?? null) ? $indicator['params'] : [],
                'value' => $this->floatValue($indicator, 'value'),
                'components' => is_array($indicator['components'] ?? null) ? $indicator['components'] : null,
                'signal' => $this->stringValue($indicator, 'signal'),
                'status' => IndicatorStatus::tryFrom($this->stringValue($indicator, 'status') ?? '')
                    ?? IndicatorStatus::Unavailable,
                'unavailable_reason' => $this->stringValue($indicator, 'unavailable_reason'),
                'evidence_id' => $this->stringValue($indicator, 'evidence_id'),
            ];
        }

        return $rows;
    }

    /**
     * Classic patterns, candlesticks and SMC events all land in `analysis_patterns`.
     *
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    public function patterns(array $result): array
    {
        $smc = $this->arrayValue($result, 'smc');
        $events = is_array($smc['events'] ?? null) ? $smc['events'] : [];

        $rows = [];
        foreach ([...$this->listValue($result, 'patterns'), ...array_filter($events, 'is_array')] as $pattern) {
            $type = $this->stringValue($pattern, 'type');
            if ($type === null) {
                continue;
            }

            $rows[] = [
                'category' => PatternCategory::tryFrom($this->stringValue($pattern, 'category') ?? '')
                    ?? PatternCategory::Classic,
                'type' => $type,
                'direction' => $this->stringValue($pattern, 'direction') ?? 'neutral',
                'status' => PatternStatus::tryFrom($this->stringValue($pattern, 'status') ?? '')
                    ?? PatternStatus::Forming,
                'confidence' => $this->floatValue($pattern, 'confidence') ?? 0.0,
                'detection_method' => DetectionMethod::tryFrom(
                    $this->stringValue($pattern, 'detection_method') ?? ''
                ) ?? DetectionMethod::Algorithmic,
                'start_time' => $this->dateValue($pattern, 'start_time'),
                'end_time' => $this->dateValue($pattern, 'end_time'),
                'price_low' => $this->floatValue($pattern, 'price_low'),
                'price_high' => $this->floatValue($pattern, 'price_high'),
                'key_points' => is_array($pattern['key_points'] ?? null) ? $pattern['key_points'] : [],
                'confirmation_rule' => $this->stringValue($pattern, 'confirmation_rule'),
                'measured_target' => $this->floatValue($pattern, 'measured_target'),
                'invalidation_price' => $this->floatValue($pattern, 'invalidation_price'),
                'evidence_id' => $this->stringValue($pattern, 'evidence_id'),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    public function levels(array $result): array
    {
        $rows = [];
        foreach ($this->listValue($result, 'levels') as $level) {
            $type = LevelType::tryFrom($this->stringValue($level, 'type') ?? '');
            $low = $this->floatValue($level, 'price_low');
            $high = $this->floatValue($level, 'price_high');
            if ($type === null || $low === null || $high === null) {
                continue;
            }

            $rows[] = [
                'type' => $type,
                'price_low' => $low,
                'price_high' => $high,
                'strength' => $this->floatValue($level, 'strength') ?? 0.0,
                'touches' => $this->intValue($level, 'touches'),
                'source' => LevelSource::tryFrom($this->stringValue($level, 'source') ?? '')
                    ?? LevelSource::Pivots,
                'pixel_y' => null,
                'evidence_id' => $this->stringValue($level, 'evidence_id'),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    public function predictions(array $result, Analysis $analysis, CarbonImmutable $expiresAt): array
    {
        $rows = [];
        foreach ($this->listValue($result, 'predictions') as $prediction) {
            $reference = $this->floatValue($prediction, 'reference_price');
            $asOf = $this->dateValue($prediction, 'as_of');
            $horizon = $this->intValue($prediction, 'horizon_bars');
            if ($reference === null || $asOf === null || $horizon === null) {
                continue;
            }

            $kind = PredictionKind::tryFrom($this->stringValue($prediction, 'kind') ?? '');
            $direction = TradeDirection::tryFrom($this->stringValue($prediction, 'direction') ?? '');
            $trigger = TriggerType::tryFrom($this->stringValue($prediction, 'trigger_type') ?? '');
            if ($kind === null || $direction === null || $trigger === null) {
                continue;
            }

            $rows[] = [
                'analysis_id' => $analysis->id,
                'user_id' => $analysis->user_id,
                'stock_id' => $analysis->stock_id,
                'kind' => $kind,
                'scenario' => Scenario::tryFrom($this->stringValue($prediction, 'scenario') ?? '')
                    ?? Scenario::Primary,
                'direction' => $direction,
                'timeframe' => $analysis->timeframe,
                'trigger_type' => $trigger,
                'trigger_price' => $this->floatValue($prediction, 'trigger_price'),
                'entry_price' => $this->floatValue($prediction, 'entry_price'),
                'stop_price' => $this->floatValue($prediction, 'stop_price'),
                'target_price_1' => $this->floatValue($prediction, 'target_price_1'),
                'target_price_2' => $this->floatValue($prediction, 'target_price_2'),
                'invalidation_price' => $this->floatValue($prediction, 'invalidation_price'),
                'reference_price' => $reference,
                'as_of' => $asOf,
                'horizon_bars' => $horizon,
                'expires_at' => $expiresAt,
                'setup_score' => $this->intValue($prediction, 'setup_score'),
                'probability' => null,
                'risk_reward' => $this->floatValue($prediction, 'risk_reward'),
                'status' => $trigger === TriggerType::Immediate
                    ? PredictionStatus::Active
                    : PredictionStatus::Pending,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function status(array $result): AnalysisStatus
    {
        return AnalysisStatus::tryFrom($this->stringValue($result, 'status') ?? '')
            ?? AnalysisStatus::Completed;
    }

    private function tier(?string $tier): ?DataTier
    {
        return match ($tier) {
            'A' => DataTier::Ohlcv,
            'B' => DataTier::Digitized,
            'C' => DataTier::VisionOnly,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function arrayValue(array $source, string $key): array
    {
        return is_array($source[$key] ?? null) ? $source[$key] : [];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     */
    private function listValue(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function stringValue(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function floatValue(array $source, string $key): ?float
    {
        $value = $source[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function intValue(array $source, string $key): ?int
    {
        $value = $source[$key] ?? null;

        return is_int($value) ? $value : (is_float($value) ? (int) round($value) : null);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function dateValue(array $source, string $key): ?CarbonImmutable
    {
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
