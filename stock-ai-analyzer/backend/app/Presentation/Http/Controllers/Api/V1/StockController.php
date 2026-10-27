<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Api\V1;

use App\Domain\Market\Models\Stock;
use App\Domain\Market\Services\StockResolver;
use App\Presentation\Http\Resources\StockResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class StockController
{
    private const int LIMIT = 20;

    /**
     * Symbol autocomplete: exact symbol first, then symbol prefix, then trigram similarity on the
     * symbol and company name (pg_trgm indexes, docs/03-database-schema.md §3.3).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'exchange' => ['nullable', 'string', 'max:16'],
        ]);

        $search = $request->string('search')->trim()->toString();
        $exchange = StockResolver::normalizeSymbol($request->string('exchange')->toString());

        $stocks = Stock::query()
            ->with('exchange')
            ->where('is_active', true)
            ->when($exchange !== '', fn (Builder $query) => $query->whereRelation('exchange', 'code', $exchange))
            ->when($search !== '', fn (Builder $query) => $this->applySearch($query, $search))
            ->orderBy('symbol')
            ->limit(self::LIMIT)
            ->get();

        return StockResource::collection($stocks);
    }

    /**
     * One stock with the stored price range per timeframe.
     */
    public function show(Stock $stock): StockResource
    {
        return (new StockResource($stock->load('exchange')))->withCoverage($stock->priceCoverage());
    }

    /**
     * One stock by its ticker, so a page can live at /stocks/PCBL rather than /stocks/14.
     */
    public function showBySymbol(string $symbol): StockResource
    {
        $stock = Stock::query()
            ->where('symbol', StockResolver::normalizeSymbol($symbol))
            ->firstOrFail();

        return new StockResource($stock);
    }

    /**
     * @param  Builder<Stock>  $query
     * @return Builder<Stock>
     */
    private function applySearch(Builder $query, string $search): Builder
    {
        $symbol = StockResolver::normalizeSymbol($search);
        $contains = '%'.$this->escapeLike($search).'%';

        return $query
            ->where(function (Builder $query) use ($symbol, $search, $contains): void {
                $query->where('symbol', 'ilike', $contains)
                    ->orWhere('name', 'ilike', $contains)
                    ->orWhereRaw('symbol % ?', [$symbol])
                    ->orWhereRaw('? <% name', [$search]);
            })
            ->orderByRaw('(symbol = ?) DESC', [$symbol])
            ->orderByRaw('(symbol LIKE ?) DESC', [$this->escapeLike($symbol).'%'])
            ->orderByRaw('GREATEST(similarity(symbol, ?), word_similarity(?, name)) DESC', [$symbol, $search]);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
