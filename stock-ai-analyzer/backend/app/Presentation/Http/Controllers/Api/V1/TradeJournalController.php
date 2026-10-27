<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Api\V1;

use App\Application\Services\TradeCalculator;
use App\Domain\Identity\Models\User;
use App\Domain\Journal\Enums\TradeStatus;
use App\Domain\Journal\Models\TradeJournalEntry;
use App\Presentation\Http\Requests\CloseTradeRequest;
use App\Presentation\Http\Requests\StoreTradeRequest;
use App\Presentation\Http\Requests\UpdateTradeRequest;
use App\Presentation\Http\Resources\TradeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The trade journal (docs/05-api-spec.md §3.5).
 *
 * The journal is what turns the app from an opinion generator into a feedback loop: Phase 6 reads
 * these rows to compare what the analysis planned with what the user actually did.
 */
final class TradeJournalController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $trades = TradeJournalEntry::query()
            ->whereBelongsTo($user)
            ->with(['stock', 'analysis'])
            ->when(
                $request->string('status')->isNotEmpty(),
                fn ($query) => $query->where('status', $request->string('status'))
            )
            ->when(
                $request->integer('stock_id') > 0,
                fn ($query) => $query->where('stock_id', $request->integer('stock_id'))
            )
            ->when(
                $request->string('tag')->isNotEmpty(),
                fn ($query) => $query->whereJsonContains('tags', $request->string('tag')->toString())
            )
            ->orderByDesc('entry_date')
            ->orderByDesc('created_at')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return TradeResource::collection($trades);
    }

    public function store(StoreTradeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $trade = new TradeJournalEntry([...$request->values(), 'user_id' => $user->id]);
        $trade->save();

        return (new TradeResource($trade->load(['stock', 'analysis'])))->response()->setStatusCode(201);
    }

    public function show(TradeJournalEntry $trade): TradeResource
    {
        Gate::authorize('view', $trade);

        return new TradeResource($trade->load(['stock', 'analysis']));
    }

    public function update(UpdateTradeRequest $request, TradeJournalEntry $trade): TradeResource
    {
        Gate::authorize('update', $trade);

        $trade->fill($request->values())->save();

        return new TradeResource($trade->load(['stock', 'analysis']));
    }

    /**
     * Close a trade: the exit fixes the P&L and the R multiple, which are then never recomputed.
     */
    public function close(
        CloseTradeRequest $request,
        TradeJournalEntry $trade,
        TradeCalculator $calculator,
    ): TradeResource {
        Gate::authorize('update', $trade);

        $exitPrice = (float) $request->float('exit_price');
        $fees = (float) $request->float('fees', 0.0);

        $trade->fill([
            ...$calculator->close($trade, $exitPrice, $fees),
            'status' => TradeStatus::Closed,
            'exit_date' => $request->date('exit_date') ?? now(),
            'exit_price' => $exitPrice,
            'exit_reason' => $request->string('exit_reason')->value(),
            'fees' => $fees,
            'followed_plan' => $request->boolean('followed_plan'),
            'lessons' => $request->string('lessons')->trim()->value() ?: null,
        ])->save();

        return new TradeResource($trade->load(['stock', 'analysis']));
    }

    public function destroy(TradeJournalEntry $trade): Response
    {
        Gate::authorize('delete', $trade);

        $trade->delete();

        return response()->noContent();
    }
}
