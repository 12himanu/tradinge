<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Api\V1;

use App\Domain\Market\Models\MarketScan;
use App\Domain\Market\Models\Stock;
use App\Presentation\Http\ApiError;
use App\Presentation\Http\Requests\MarketScanRequest;
use App\Presentation\Http\Resources\MarketScanResource;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The market scanner: the latest engine reading of every stock, filtered and sorted.
 *
 * Scans are shared reference data like price bars, not per-user resources, so there is no ownership
 * filter here — only authentication.
 */
final class MarketScanController
{
    private const int MAX_PER_PAGE = 200;

    private const int MAX_PER_GROUP = 25;

    /** Ordered as a reader scans them: what to do, then what to avoid. */
    private const array HOTLIST_DECISIONS = ['buy', 'hold', 'sell', 'wait', 'avoid'];

    private const string SCORE_NOTE = 'setup_score ranks setups; it is not a probability. '
        .'Probabilities appear only as base rates, each with its sample size.';

    /**
     * One row per stock, newest scan first. Filters narrow the market down to what is worth reading.
     */
    public function index(MarketScanRequest $request): AnonymousResourceCollection
    {
        $session = $request->scannedOn();
        $latest = MarketScan::query()->max('scanned_on');

        $query = MarketScan::query()->with('stock:id,symbol,name,sector');
        $this->scopeToReading($query, $session);
        // A stock the engine could not read has nothing to rank; it is reported by `summary`.
        $query->whereNull('error_code');

        $this->applyFilters($query, $request);

        $perPage = min($request->perPage(), self::MAX_PER_PAGE);
        $scans = $query
            ->orderByRaw($this->ordering($request->sort()))
            ->orderBy('stock_id')
            ->paginate($perPage)
            ->withQueryString();

        return MarketScanResource::collection($scans)->additional([
            'meta' => [
                'scanned_on' => $session ?? (is_string($latest) ? $latest : null),
                'session_only' => $session !== null,
                'summary' => $this->summary($session),
                // Said once, here, so no client has to infer it: the score is a ranking.
                'score_note' => self::SCORE_NOTE,
            ],
        ]);
    }

    /**
     * The few stocks worth looking at, grouped by what the decision policy said.
     *
     * The scanner answers "show me everything, ranked"; this answers the question a person
     * actually opens the app with — is there anything to do today. Each group is capped, because a
     * list of forty "buy" rows is the same as no list at all.
     */
    public function hotlist(MarketScanRequest $request): JsonResponse
    {
        $perGroup = min(max($request->perPage(), 1), self::MAX_PER_GROUP);
        $groups = [];

        foreach (self::HOTLIST_DECISIONS as $decision) {
            $query = MarketScan::query()->with('stock:id,symbol,name,sector');
            $this->scopeToReading($query, null);

            $scans = $query
                ->whereNull('error_code')
                ->where('decision', $decision)
                // Within a decision, the strongest chart first; a viable plan breaks ties.
                ->orderByDesc('has_viable_plan')
                ->orderByRaw('setup_score desc nulls last')
                ->limit($perGroup)
                ->get();

            $groups[$decision] = MarketScanResource::collection($scans)->resolve();
        }

        return new JsonResponse([
            'data' => $groups,
            'meta' => [
                'scanned_on' => MarketScan::query()->max('scanned_on'),
                'summary' => $this->summary(null),
                'per_group' => $perGroup,
                'score_note' => self::SCORE_NOTE,
            ],
        ]);
    }

    /**
     * One stock's latest scan with the full detail the drawer renders.
     */
    public function show(Stock $stock): JsonResponse|MarketScanResource
    {
        $scan = MarketScan::query()
            ->with('stock:id,symbol,name,sector')
            ->where('stock_id', $stock->id)
            ->orderByDesc('scanned_on')
            ->first();

        if ($scan === null) {
            return ApiError::response(
                'NOT_SCANNED',
                "{$stock->symbol} has not been scanned yet.",
                404,
            );
        }

        return (new MarketScanResource($scan))->additional(['detail' => true]);
    }

    private function applyFilters(Builder $query, MarketScanRequest $request): void
    {
        $query
            ->when($request->bias() !== null, fn (Builder $q) => $q->where('bias', $request->bias()))
            ->when($request->decision() !== null, fn (Builder $q) => $q->where('decision', $request->decision()))
            ->when($request->rangeZone() !== null, fn (Builder $q) => $q->where('range_zone', $request->rangeZone()))
            ->when($request->minScore() !== null, fn (Builder $q) => $q->where('setup_score', '>=', $request->minScore()))
            ->when($request->hasTraps(), fn (Builder $q) => $q->where('trap_count', '>', 0))
            ->when($request->hasOpportunities(), fn (Builder $q) => $q->where('opportunity_count', '>', 0))
            ->when($request->viablePlanOnly(), fn (Builder $q) => $q->where('has_viable_plan', true))
            ->when($request->search() !== null, function (Builder $q) use ($request): void {
                $term = '%'.$request->search().'%';
                $q->whereHas('stock', fn (Builder $s) => $s
                    ->where('symbol', 'ilike', $term)
                    ->orWhere('name', 'ilike', $term));
            });
    }

    /**
     * Nulls last in every ordering: an unscored field should never head the list.
     *
     * @return literal-string every branch is a constant, so nothing user-supplied reaches SQL
     */
    private function ordering(string $sort): string
    {
        return match ($sort) {
            'score_asc' => 'setup_score asc nulls last',
            'traps' => 'trap_count desc, setup_score desc nulls last',
            'opportunities' => 'opportunity_count desc, setup_score desc nulls last',
            'symbol' => 'stock_id asc',
            default => 'setup_score desc nulls last',
        };
    }

    /**
     * Narrows to one reading per stock.
     *
     * By default that is each stock's most recent scan, not every scan filed on the newest date.
     * Plenty of NEPSE securities go days without trading, so their last bar — and therefore their
     * last reading — is older than the market's. Keying the view to a single date would quietly
     * drop them, and an illiquid stock is exactly the kind a scanner is supposed to surface.
     *
     * Passing `scanned_on` asks the other question instead: what did one particular session look
     * like.
     */
    private function scopeToReading(Builder $query, ?string $session): void
    {
        if ($session !== null) {
            $query->where('scanned_on', $session);

            return;
        }

        $query->whereIn('id', function ($sub): void {
            $sub->selectRaw('DISTINCT ON (stock_id) id')
                ->from('market_scans')
                ->orderBy('stock_id')
                ->orderByDesc('scanned_on');
        });
    }

    /**
     * What the whole market looks like, so the page can lead with the shape of it.
     *
     * @return array<string, mixed>
     */
    private function summary(?string $session): array
    {
        $query = MarketScan::query();
        $this->scopeToReading($query, $session);

        $row = $query
            ->selectRaw('count(*) FILTER (WHERE error_code IS NULL) AS scanned')
            ->selectRaw('count(*) FILTER (WHERE error_code IS NOT NULL) AS unreadable')
            ->selectRaw("count(*) FILTER (WHERE bias = 'bullish') AS bullish")
            ->selectRaw("count(*) FILTER (WHERE bias = 'bearish') AS bearish")
            ->selectRaw("count(*) FILTER (WHERE bias = 'neutral') AS neutral")
            ->selectRaw('count(*) FILTER (WHERE trap_count > 0) AS with_traps')
            ->selectRaw('count(*) FILTER (WHERE opportunity_count > 0) AS with_opportunities')
            ->selectRaw('count(*) FILTER (WHERE has_viable_plan) AS viable_plans')
            ->toBase()
            ->first();

        return [
            'scanned' => (int) ($row->scanned ?? 0),
            'unreadable' => (int) ($row->unreadable ?? 0),
            'bullish' => (int) ($row->bullish ?? 0),
            'bearish' => (int) ($row->bearish ?? 0),
            'neutral' => (int) ($row->neutral ?? 0),
            'with_traps' => (int) ($row->with_traps ?? 0),
            'with_opportunities' => (int) ($row->with_opportunities ?? 0),
            'viable_plans' => (int) ($row->viable_plans ?? 0),
        ];
    }
}
