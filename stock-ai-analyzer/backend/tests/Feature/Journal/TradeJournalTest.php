<?php

declare(strict_types=1);

use App\Domain\Analysis\Models\Analysis;
use App\Domain\Identity\Models\User;
use App\Domain\Journal\Enums\TradeSide;
use App\Domain\Journal\Enums\TradeStatus;
use App\Domain\Journal\Models\TradeJournalEntry;
use App\Domain\Market\Models\Stock;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->stock = Stock::factory()->create(['symbol' => 'HDL']);
    $this->actingAs($this->user);
});

function tradePayload(array $overrides = []): array
{
    return [
        'stock_id' => test()->stock->id,
        'side' => 'long',
        'entry_date' => now()->toDateString(),
        'entry_price' => 550,
        'quantity' => 100,
        'stop_loss' => 520,
        'target_price' => 620,
        'strategy' => 'Breakout',
        'reason' => 'Ascending triangle breakout with volume confirmation.',
        'tags' => ['nepse', 'swing'],
        ...$overrides,
    ];
}

it('records a trade', function () {
    $this->postJson('/api/v1/trades', tradePayload())
        ->assertCreated()
        ->assertJsonPath('data.side', 'long')
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.entry_price', 550)
        ->assertJsonPath('data.tags', ['nepse', 'swing']);

    expect(TradeJournalEntry::query()->count())->toBe(1);
});

it('can be prefilled from an analysis trade plan', function () {
    $analysis = Analysis::factory()->for($this->user)->create();

    $this->postJson('/api/v1/trades', tradePayload(['analysis_id' => $analysis->id]))
        ->assertCreated()
        ->assertJsonPath('data.analysis_id', $analysis->id);
});

it('validates the entry', function () {
    $this->postJson('/api/v1/trades', tradePayload(['entry_price' => 0, 'quantity' => -5]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['entry_price', 'quantity']);
});

it('rejects a stop on the wrong side of the entry', function () {
    $this->postJson('/api/v1/trades', tradePayload(['side' => 'short', 'stop_loss' => 520, 'target_price' => 620]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['stop_loss', 'target_price']);
});

it('insists on a reason, because that is what the journal is for', function () {
    $payload = tradePayload();
    unset($payload['reason']);

    $this->postJson('/api/v1/trades', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

it('closes a long trade and computes P&L and R', function () {
    $trade = TradeJournalEntry::factory()->for($this->user)->create([
        'stock_id' => $this->stock->id,
        'side' => TradeSide::Long,
        'status' => TradeStatus::Open,
        'entry_price' => 550,
        'quantity' => 100,
        'stop_loss' => 520,
    ]);

    $this->postJson("/api/v1/trades/{$trade->id}/close", [
        'exit_price' => 610,
        'exit_reason' => 'target',
        'fees' => 400,
        'followed_plan' => true,
    ])->assertOk()
        ->assertJsonPath('data.status', 'closed')
        // 60 a share on 100 shares, less 400 in fees.
        ->assertJsonPath('data.gross_pnl', 6000)
        ->assertJsonPath('data.net_pnl', 5600)
        // Risk was 30 a share on 100 shares = 3000, so 5600 is 1.87R.
        ->assertJsonPath('data.r_multiple', 1.87);
});

it('closes a short trade the other way round', function () {
    $trade = TradeJournalEntry::factory()->for($this->user)->create([
        'stock_id' => $this->stock->id,
        'side' => TradeSide::Short,
        'status' => TradeStatus::Open,
        'entry_price' => 550,
        'quantity' => 100,
        'stop_loss' => 580,
        // The schema enforces stop > entry > target for a short.
        'target_price' => 500,
    ]);

    $this->postJson("/api/v1/trades/{$trade->id}/close", [
        'exit_price' => 500,
        'exit_reason' => 'target',
    ])->assertOk()
        ->assertJsonPath('data.gross_pnl', 5000)
        ->assertJsonPath('data.r_multiple', 1.67);
});

it('reports a loss honestly', function () {
    $trade = TradeJournalEntry::factory()->for($this->user)->create([
        'stock_id' => $this->stock->id,
        'side' => TradeSide::Long,
        'status' => TradeStatus::Open,
        'entry_price' => 550,
        'quantity' => 100,
        'stop_loss' => 520,
    ]);

    $this->postJson("/api/v1/trades/{$trade->id}/close", [
        'exit_price' => 520,
        'exit_reason' => 'stop',
        'followed_plan' => true,
    ])->assertOk()
        ->assertJsonPath('data.net_pnl', -3000)
        ->assertJsonPath('data.r_multiple', -1);
});

it('leaves R null when the trade had no stop', function () {
    $trade = TradeJournalEntry::factory()->for($this->user)->create([
        'stock_id' => $this->stock->id,
        'side' => TradeSide::Long,
        'status' => TradeStatus::Open,
        'entry_price' => 550,
        'quantity' => 100,
        'stop_loss' => null,
    ]);

    $this->postJson("/api/v1/trades/{$trade->id}/close", ['exit_price' => 600, 'exit_reason' => 'manual'])
        ->assertOk()
        ->assertJsonPath('data.r_multiple', null);
});

it('lists only your own trades', function () {
    TradeJournalEntry::factory()->for($this->user)->create(['stock_id' => $this->stock->id]);
    TradeJournalEntry::factory()->for(User::factory())->create();

    expect($this->getJson('/api/v1/trades')->assertOk()->json('data'))->toHaveCount(1);
});

it('filters by status', function () {
    TradeJournalEntry::factory()->for($this->user)->create([
        'stock_id' => $this->stock->id,
        'status' => TradeStatus::Open,
    ]);
    TradeJournalEntry::factory()->for($this->user)->closed()->create(['stock_id' => $this->stock->id]);

    expect($this->getJson('/api/v1/trades?status=open')->assertOk()->json('data'))->toHaveCount(1);
});

it('hides another user\'s trade behind a 404', function () {
    $stranger = TradeJournalEntry::factory()->for(User::factory())->create();

    $this->getJson("/api/v1/trades/{$stranger->id}")->assertNotFound();
    $this->patchJson("/api/v1/trades/{$stranger->id}", ['strategy' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/trades/{$stranger->id}")->assertNotFound();
});

it('updates the notes on an open trade', function () {
    $trade = TradeJournalEntry::factory()->for($this->user)->create(['stock_id' => $this->stock->id]);

    $this->patchJson("/api/v1/trades/{$trade->id}", [
        'lessons' => 'Waited for the retest instead of chasing.',
        'tags' => ['patience'],
    ])->assertOk()->assertJsonPath('data.tags', ['patience']);
});

it('soft deletes a trade', function () {
    $trade = TradeJournalEntry::factory()->for($this->user)->create(['stock_id' => $this->stock->id]);

    $this->deleteJson("/api/v1/trades/{$trade->id}")->assertNoContent();

    expect(TradeJournalEntry::query()->count())->toBe(0)
        ->and(TradeJournalEntry::withTrashed()->count())->toBe(1);
});
