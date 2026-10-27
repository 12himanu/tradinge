<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Analysis\Models\Analysis;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Models\Stock;
use Illuminate\Support\Collection;

/**
 * Builds the engine's `AnalysisRequest` payload (P4D-03).
 *
 * Shape and field names come from `ai-engine/contracts/analysis_request.schema.json`; the contract
 * test in `tests/Feature/Analysis/EngineContractTest.php` validates what this produces against it.
 */
final readonly class EngineRequestBuilder
{
    /** Bars beyond this add nothing: the engine trims to the style's lookback anyway. */
    public const int MAX_BARS = 600;

    public function __construct(
        private FeeScheduleResolver $fees,
        private HistoricalContextBuilder $history,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Analysis $analysis): array
    {
        $analysis->loadMissing(['user.defaultExchange', 'stock.exchange', 'chartUpload']);
        $user = $analysis->user;
        $stock = $analysis->stock;
        $settings = is_array($user?->settings) ? $user->settings : [];
        $exchange = $stock?->exchange;
        $exchange ??= $user?->defaultExchange;

        $equity = $this->floatOr($settings['account_size'] ?? null, 100_000.0);
        $maxPositionPct = $this->floatOr($settings['max_position_pct'] ?? null, 25.0);
        $nominalPosition = $equity * $maxPositionPct / 100;

        return [
            'schema_version' => '1.0',
            'analysis_id' => $analysis->id,
            'correlation_id' => $analysis->id,
            'exchange' => $exchange === null ? 'NEPSE' : $exchange->code,
            'symbol' => $stock?->symbol,
            'declared_symbol' => $analysis->chartUpload?->declared_symbol,
            // For the engine's chart-identity check: NEPSE charting sites print the company name,
            // not the ticker, so the ticker alone cannot tell whether the screenshot is this stock.
            'company_name' => $stock?->name,
            'timeframe' => $analysis->timeframe->value,
            'trading_style' => $analysis->trading_style->value,
            'position' => [
                'status' => $analysis->position_status->value,
                'entry_price' => $this->nullableFloat($analysis->position_entry_price),
                'quantity' => $this->nullableFloat($analysis->position_quantity),
                'stop_price' => null,
            ],
            'account' => [
                'equity' => $equity,
                'risk_pct' => $this->floatOr($settings['risk_per_trade_pct'] ?? null, 1.0),
                'max_position_pct' => $maxPositionPct,
                'lot_size' => $exchange === null ? 10 : $exchange->lot_size,
                'currency' => is_string($settings['currency'] ?? null) ? $settings['currency'] : 'NPR',
            ],
            'fees' => $this->fees->resolve($exchange, $nominalPosition),
            'bars' => $this->bars($stock, $analysis),
            'as_of' => $analysis->as_of?->toIso8601String(),
            'image_path' => $this->imagePath($analysis),
            'user_thesis' => $analysis->user_thesis,
            'symbol_universe' => $this->symbolUniverse($exchange?->id),
            // The user has vouched for this pairing of chart and ticker, so the engine does not
            // get to refuse it again.
            'skip_symbol_check' => (bool) $analysis->chartUpload?->symbol_confirmed,
            'force_tier' => null,
            'historical_context' => $this->history->build($analysis),
        ];
    }

    /**
     * Bars up to `as_of`, oldest first. Without a linked stock there is no Tier A data at all.
     *
     * @return list<array<string, mixed>>
     */
    private function bars(?Stock $stock, Analysis $analysis): array
    {
        if ($stock === null) {
            return [];
        }

        /** @var Collection<int, PriceBar> $bars */
        $bars = PriceBar::query()
            ->where('stock_id', $stock->id)
            ->where('timeframe', $analysis->timeframe->value)
            ->when($analysis->as_of !== null, fn ($query) => $query->where('bar_time', '<=', $analysis->as_of))
            ->orderByDesc('bar_time')
            ->limit(self::MAX_BARS)
            ->get()
            ->reverse()
            ->values();

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
     * The engine's storage root is the shared `storage/` folder; charts live under `charts/`.
     */
    private function imagePath(Analysis $analysis): ?string
    {
        $upload = $analysis->chartUpload;

        return $upload === null ? null : 'charts/'.ltrim($upload->path, '/');
    }

    /**
     * @return list<string>
     */
    private function symbolUniverse(?int $exchangeId): array
    {
        if ($exchangeId === null) {
            return [];
        }

        /** @var list<string> $symbols */
        $symbols = Stock::query()
            ->where('exchange_id', $exchangeId)
            ->orderBy('symbol')
            ->pluck('symbol')
            ->all();

        return $symbols;
    }

    private function floatOr(mixed $value, float $fallback): float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : $fallback;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
