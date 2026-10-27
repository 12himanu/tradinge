<?php

declare(strict_types=1);

namespace App\Application\Actions;

use App\Application\DTOs\RequestAnalysisData;
use App\Application\Jobs\AnalyzeChartJob;
use App\Domain\Analysis\Enums\AnalysisStatus;
use App\Domain\Analysis\Exceptions\AnalysisAlreadyRunning;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Chart\Models\ChartUpload;
use App\Domain\Identity\Models\User;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Services\StockResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Queue an analysis for a chart upload (P4D-03).
 *
 * Analyses are expensive and deterministic for a given input, so an identical completed run is
 * reused unless the caller forces a new one. A run already in flight is a conflict, not a second
 * queue entry: two jobs for the same chart would burn the GPU twice for the same answer.
 */
final readonly class RequestAnalysis
{
    public function __construct(private StockResolver $stocks) {}

    /**
     * @throws AnalysisAlreadyRunning
     */
    public function __invoke(User $user, ChartUpload $upload, RequestAnalysisData $data): Analysis
    {
        $running = $this->runningAnalysis($upload);
        if ($running !== null) {
            throw new AnalysisAlreadyRunning($running);
        }

        // Before the price lookup, because attaching a ticker is what makes there be any prices.
        $linked = $this->linkSymbol($user, $upload, $data);
        $this->applyConfirmation($upload, $data, relinked: $linked);
        $priceData = $this->priceData($upload);

        // A newly attached symbol changes the whole answer, so an earlier vision-only run of the
        // same chart is not something to hand back.
        if (! $data->force && ! $linked) {
            $reusable = $this->reusableAnalysis($upload, $data, $priceData);
            if ($reusable !== null) {
                return $reusable;
            }
        }

        $analysis = DB::transaction(function () use ($user, $upload, $data, $priceData): Analysis {
            $analysis = new Analysis([
                'user_id' => $user->id,
                'chart_upload_id' => $upload->id,
                'stock_id' => $upload->stock_id,
                'timeframe' => $data->timeframe,
                'trading_style' => $data->tradingStyle,
                'position_status' => $data->positionStatus,
                'position_entry_price' => $data->positionEntryPrice,
                'position_quantity' => $data->positionQuantity,
                'user_thesis' => $data->userThesis,
                'status' => AnalysisStatus::Queued,
                'as_of' => $priceData['as_of'],
                'price_bar_count' => $priceData['count'],
                'queued_at' => CarbonImmutable::now(),
                'warnings' => [],
            ]);
            $analysis->save();

            return $analysis;
        });

        AnalyzeChartJob::dispatch($analysis->id);

        return $analysis;
    }

    /**
     * The price data available to this chart right now: the latest bar and how many there are.
     *
     * `as_of` is the close the chart is evaluated at — Phase 4B replaces it with the date read off
     * the screenshot's x-axis. The count exists for the reuse check: it is the half of the
     * fingerprint that a back-fill of older history moves while `as_of` stays put.
     *
     * @return array{as_of: CarbonImmutable|null, count: int|null}
     */
    private function priceData(ChartUpload $upload): array
    {
        if ($upload->stock_id === null) {
            return ['as_of' => null, 'count' => null];
        }

        $bars = PriceBar::query()
            ->where('stock_id', $upload->stock_id)
            ->where('timeframe', $upload->declared_timeframe);

        $latest = (clone $bars)->max('bar_time');

        return [
            'as_of' => is_string($latest) ? CarbonImmutable::parse($latest) : null,
            'count' => $bars->count(),
        ];
    }

    /**
     * Attach the ticker the user supplied now, whether or not the chart already had one.
     *
     * Uploading without a symbol strands a chart at the vision-only tier, and re-uploading the
     * same image cannot fix it: the sha256 dedupe returns the original row and drops whatever was
     * typed. So this is the one place the correction can happen.
     *
     * It is also the only way to correct a chart attached to the *wrong* ticker — a mis-typed
     * symbol, a stale one left in the form, one the engine's chart-identity check has just
     * rejected as belonging to another company. This used to leave such an upload alone on the
     * grounds that silently re-pointing a chart would be worse than refusing; it did not refuse
     * either, so the user typed the right symbol, got no error, and received the same report about
     * the wrong instrument. A symbol passed in here is an explicit correction by the person
     * looking at the screenshot, and it wins.
     *
     * Returns whether anything changed, which is what makes an earlier run non-reusable.
     */
    private function linkSymbol(User $user, ChartUpload $upload, RequestAnalysisData $data): bool
    {
        if ($data->symbol === null) {
            return false;
        }

        // The same fallback chain UploadChart uses, so a symbol resolves here exactly as it would
        // have at upload time. Without it the resolver refuses on a null exchange and the chart
        // stays stranded no matter what the user types.
        $exchange = $data->exchange
            ?? $upload->declared_exchange
            ?? $user->loadMissing('defaultExchange')->defaultExchange?->code;

        $stock = $this->stocks->resolve($exchange, $data->symbol);

        // An unknown ticker is not a correction: it names nothing to re-point at, and dropping the
        // link the chart already has would only lose information.
        if ($stock === null || $stock->id === $upload->stock_id) {
            return false;
        }

        $upload->forceFill([
            'stock_id' => $stock->id,
            'declared_symbol' => $stock->symbol,
        ])->save();

        return true;
    }

    /**
     * Record — or drop — the user's confirmation that this chart really is the ticker on it.
     *
     * A confirmation belongs to the pairing of image and symbol, so re-pointing the chart at a
     * different ticker throws it away: having vouched for one company says nothing about another.
     */
    private function applyConfirmation(ChartUpload $upload, RequestAnalysisData $data, bool $relinked): void
    {
        $confirmed = $data->confirmSymbol && ! $relinked;

        if ($upload->symbol_confirmed === $confirmed) {
            return;
        }

        $upload->forceFill(['symbol_confirmed' => $confirmed])->save();
    }

    private function runningAnalysis(ChartUpload $upload): ?Analysis
    {
        return Analysis::query()
            ->where('chart_upload_id', $upload->id)
            ->whereIn('status', [AnalysisStatus::Queued->value, AnalysisStatus::Running->value])
            ->latest()
            ->first();
    }

    /**
     * A completed run with the same inputs answers the same question; re-running only spends time.
     *
     * **The price data is part of the inputs.** Matching on the chart, timeframe, style and
     * position alone meant an import of the OHLCV that an analysis was missing never invalidated
     * it: the user imported the CSV, re-ran, and got the same "Tier C, no numbers can be given"
     * report back, with nothing to suggest the data had ever arrived.
     *
     * @param  array{as_of: CarbonImmutable|null, count: int|null}  $priceData
     */
    private function reusableAnalysis(ChartUpload $upload, RequestAnalysisData $data, array $priceData): ?Analysis
    {
        $candidate = Analysis::query()
            ->where('chart_upload_id', $upload->id)
            ->whereIn('status', [AnalysisStatus::Completed->value, AnalysisStatus::Partial->value])
            ->where('timeframe', $data->timeframe->value)
            ->where('trading_style', $data->tradingStyle->value)
            ->where('position_status', $data->positionStatus->value)
            ->latest()
            ->first();

        if ($candidate === null) {
            return null;
        }

        return $this->sawTheSameBars($candidate, $priceData) ? $candidate : null;
    }

    /**
     * Whether an existing analysis was built on the price data we would send today.
     *
     * Analyses written before `price_bar_count` existed carry null; those are compared on `as_of`
     * alone rather than being thrown away, since that is all they recorded.
     *
     * @param  array{as_of: CarbonImmutable|null, count: int|null}  $priceData
     */
    private function sawTheSameBars(Analysis $candidate, array $priceData): bool
    {
        $asOf = $priceData['as_of'];
        $sameAsOf = $candidate->as_of === null || $asOf === null
            ? $candidate->as_of === null && $asOf === null
            : $candidate->as_of->equalTo($asOf);

        if (! $sameAsOf) {
            return false;
        }

        return $candidate->price_bar_count === null || $candidate->price_bar_count === $priceData['count'];
    }
}
