<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Analysis\Contracts\AnalysisEngine;
use App\Domain\Analysis\Exceptions\EngineBusy;
use App\Domain\Analysis\Exceptions\EngineRequestFailed;
use App\Domain\Analysis\Exceptions\EngineStreamTimedOut;
use App\Domain\Analysis\Exceptions\EngineUnavailable;
use App\Domain\Identity\Models\User;
use App\Domain\Market\Models\MarketScan;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Models\Stock;
use App\Domain\Market\Services\SignalClassifier;
use App\Domain\Market\ValueObjects\ScanSignal;
use App\Domain\Shared\Enums\Timeframe;
use App\Domain\Shared\Enums\TradingStyle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Runs the engine over one stock's stored bars and files the reading as a MarketScan.
 *
 * The engine needs no screenshot: with enough bars it runs the full Tier A pipeline — indicators,
 * structure, SMC zones, patterns, levels, scoring, risk, decision — and the chart upload was only
 * ever the way bars got in. Now that they arrive nightly from merolagani, the same analysis can run
 * across the whole market.
 *
 * What is deliberately dropped on the way out: the engine's `predictions`. Those belong to an
 * analysis the user asked for, because they become the track record. A scan is a reading.
 */
final readonly class ScanStock
{
    /**
     * Bars sent to the engine. Far more than the ~250 any style looks back over, because base rates
     * are measured on the whole history and a bigger sample is the entire point of them.
     */
    public const int MAX_BARS = 2000;

    /**
     * Below this the engine falls back to its vision-only tier, which needs an image it will not
     * get here. Matches MIN_BARS_TIER_A in the engine.
     */
    public const int MIN_BARS = 60;

    public function __construct(
        private AnalysisEngine $engine,
        private FeeScheduleResolver $fees,
        private SignalClassifier $classifier,
    ) {}

    public function __invoke(Stock $stock, TradingStyle $style, ?User $user = null): MarketScan
    {
        $bars = $this->bars($stock);

        if ($bars->count() < self::MIN_BARS) {
            return $this->record($stock, $style, $bars, [
                'error_code' => 'INSUFFICIENT_DATA',
                'error_message' => sprintf(
                    'Needs at least %d daily bars; %d stored.',
                    self::MIN_BARS,
                    $bars->count(),
                ),
            ]);
        }

        try {
            $result = $this->engine->analyze($this->request($stock, $style, $bars, $user));
        } catch (EngineUnavailable|EngineBusy|EngineStreamTimedOut $e) {
            // Transient: rethrown so the queue retries rather than filing an empty reading.
            throw $e;
        } catch (EngineRequestFailed $e) {
            // The engine looked at this stock and could not analyse it. That is an answer, and it
            // belongs in the row so the scanner can say why the stock is missing.
            return $this->record($stock, $style, $bars, [
                // The engine's own code, so the scanner can say INSUFFICIENT_DATA rather than
                // "something went wrong".
                'error_code' => Str::limit($e->errorCode, 40, ''),
                'error_message' => Str::limit($e->getMessage(), 500),
            ]);
        }

        return $this->record($stock, $style, $bars, $this->attributes($result));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function attributes(array $result): array
    {
        $smc = $this->arrayValue($result, 'smc');
        $decision = $this->arrayValue($result, 'decision');
        $dealingRange = is_array($smc['dealing_range'] ?? null) ? $smc['dealing_range'] : null;
        $bias = is_string($result['bias'] ?? null) ? $result['bias'] : null;
        $structure = is_string($smc['structure'] ?? null) ? $smc['structure'] : null;

        $signals = $this->classifier->classify(
            events: $this->listValue($smc, 'events'),
            patterns: $this->listValue($result, 'patterns'),
            dealingRange: $dealingRange,
            bias: $bias,
            structure: $structure,
        );

        $plans = $this->listValue($result, 'trade_plans');
        $score = $this->arrayValue($result, 'score');

        return [
            'as_of' => is_string($result['as_of'] ?? null) ? CarbonImmutable::parse($result['as_of']) : null,
            'last_price' => $this->numeric($result['last_price'] ?? null),
            'bias' => $bias,
            'setup_score' => is_int($result['setup_score'] ?? null) ? $result['setup_score'] : null,
            'decision' => is_string($decision['action'] ?? null) ? $decision['action'] : null,
            'decision_rationale' => is_string($decision['rationale'] ?? null) ? $decision['rationale'] : null,
            'structure' => $structure,
            'last_event' => is_string($smc['last_event'] ?? null) ? $smc['last_event'] : null,
            'range_zone' => is_string($dealingRange['zone'] ?? null) ? $dealingRange['zone'] : null,
            'range_position' => $this->numeric($dealingRange['position'] ?? null),
            'trap_count' => $this->countOf($signals, SignalClassifier::TRAP),
            'opportunity_count' => $this->countOf($signals, SignalClassifier::OPPORTUNITY),
            'has_viable_plan' => $this->anyViable($plans),
            'signals' => array_map(static fn (ScanSignal $s): array => $s->toArray(), $signals),
            'base_rates' => $this->listValue($result, 'base_rates'),
            // What the next day and the next week have been worth on this symbol, measured. It is
            // the whole content of the forecast page, and it costs nothing to keep here: the
            // engine measured it on the same history it already walked for the base rates.
            'outlook' => $this->listValue($result, 'outlook'),
            'levels' => $this->listValue($result, 'levels'),
            'plans' => $plans,
            'factors' => $this->listValue($score, 'factors'),
            'warnings' => $this->stringList($result, 'warnings'),
            'report_markdown' => is_string($result['report_markdown'] ?? null) ? $result['report_markdown'] : null,
            'bar_count' => is_int($result['bar_count'] ?? null) ? $result['bar_count'] : 0,
            'data_quality' => $this->numeric($result['data_quality'] ?? null),
            'engine_version' => is_string($result['engine_version'] ?? null) ? $result['engine_version'] : null,
            'error_code' => null,
            'error_message' => null,
        ];
    }

    /**
     * @param  Collection<int, PriceBar>  $bars
     * @param  array<string, mixed>  $attributes
     */
    private function record(Stock $stock, TradingStyle $style, Collection $bars, array $attributes): MarketScan
    {
        $lastBar = $bars->last();
        // Keyed on the session, not the clock, so a re-run on the same data revises one row.
        $scannedOn = $lastBar?->bar_time->toDateString() ?? CarbonImmutable::now('UTC')->toDateString();

        $scan = MarketScan::query()->updateOrCreate(
            ['stock_id' => $stock->id, 'scanned_on' => $scannedOn],
            [
                'timeframe' => Timeframe::D1,
                'trading_style' => $style,
                'as_of' => $lastBar?->bar_time,
                'last_price' => $lastBar?->close,
                'bar_count' => $bars->count(),
                ...$attributes,
            ],
        );

        return $scan;
    }

    /**
     * @param  Collection<int, PriceBar>  $bars
     * @return array<string, mixed>
     */
    private function request(Stock $stock, TradingStyle $style, Collection $bars, ?User $user): array
    {
        $exchange = $stock->loadMissing('exchange')->exchange;
        $settings = is_array($user?->settings) ? $user->settings : [];

        $equity = $this->floatOr($settings['account_size'] ?? null, 100_000.0);
        $maxPositionPct = $this->floatOr($settings['max_position_pct'] ?? null, 25.0);

        return [
            'schema_version' => '1.0',
            // Scans are not analyses; the id is for tracing this call, not a row anywhere.
            'analysis_id' => (string) Str::ulid(),
            'correlation_id' => 'scan:'.$stock->symbol,
            'exchange' => $exchange->code ?? 'NEPSE',
            'symbol' => $stock->symbol,
            'timeframe' => Timeframe::D1->value,
            'trading_style' => $style->value,
            'position' => ['status' => 'none', 'entry_price' => null, 'quantity' => null, 'stop_price' => null],
            'account' => [
                'equity' => $equity,
                'risk_pct' => $this->floatOr($settings['risk_per_trade_pct'] ?? null, 1.0),
                'max_position_pct' => $maxPositionPct,
                'lot_size' => $exchange->lot_size ?? 10,
                'currency' => is_string($settings['currency'] ?? null) ? $settings['currency'] : 'NPR',
            ],
            'fees' => $this->fees->resolve($exchange, $equity * $maxPositionPct / 100),
            'bars' => $this->barRows($bars),
            'as_of' => $bars->last()?->bar_time->toIso8601String(),
            // No screenshot, and none needed: bars alone carry the analysis to Tier A.
            'image_path' => null,
            'user_thesis' => null,
            'symbol_universe' => [],
            'force_tier' => null,
            'historical_context' => [],
        ];
    }

    /**
     * @return Collection<int, PriceBar>
     */
    private function bars(Stock $stock): Collection
    {
        /** @var Collection<int, PriceBar> $bars */
        $bars = PriceBar::query()
            ->where('stock_id', $stock->id)
            ->where('timeframe', Timeframe::D1->value)
            ->orderByDesc('bar_time')
            ->limit(self::MAX_BARS)
            ->get()
            ->reverse()
            ->values();

        return $bars;
    }

    /**
     * @param  Collection<int, PriceBar>  $bars
     * @return list<array<string, mixed>>
     */
    private function barRows(Collection $bars): array
    {
        $rows = [];
        foreach ($bars as $bar) {
            $rows[] = [
                'date' => $bar->bar_time->toIso8601String(),
                'open' => (float) $bar->open,
                'high' => (float) $bar->high,
                'low' => (float) $bar->low,
                'close' => (float) $bar->close,
                'volume' => $bar->volume === null ? null : (float) $bar->volume,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<ScanSignal>  $signals
     */
    private function countOf(array $signals, string $kind): int
    {
        return count(array_filter($signals, static fn (ScanSignal $s): bool => $s->kind === $kind));
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     */
    private function anyViable(array $plans): bool
    {
        foreach ($plans as $plan) {
            if (($plan['viable'] ?? false) === true) {
                return true;
            }
        }

        return false;
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
     * @return list<string>
     */
    private function stringList(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     */
    private function listValue(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return is_array($value) ? array_values($value) : [];
    }

    private function numeric(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private function floatOr(mixed $value, float $fallback): float
    {
        return is_int($value) || is_float($value) ? (float) $value : $fallback;
    }
}
