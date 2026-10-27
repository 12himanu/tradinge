<?php

declare(strict_types=1);

use App\Domain\Analysis\Enums\Decision;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Identity\Models\User;
use App\Domain\Journal\Enums\ExitReason;
use App\Domain\Journal\Enums\TradeSide;
use App\Domain\Journal\Enums\TradeStatus;
use App\Domain\Journal\Models\TradeJournalEntry;
use App\Domain\Market\Models\Stock;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->stock = Stock::factory()->create(['symbol' => 'HDL']);
    $this->actingAs($this->user);
});

function closedTrade(User $user, int $stockId, array $overrides = []): TradeJournalEntry
{
    return TradeJournalEntry::factory()->for($user)->create([
        'stock_id' => $stockId,
        'side' => TradeSide::Long,
        'status' => TradeStatus::Closed,
        'entry_price' => 550,
        'quantity' => 100,
        'stop_loss' => 520,
        'target_price' => 620,
        'exit_date' => now()->subDays(3),
        'exit_price' => 600,
        'exit_reason' => ExitReason::Target,
        'net_pnl' => 5000,
        'r_multiple' => 1.67,
        'followed_plan' => true,
        ...$overrides,
    ]);
}

it('reports nothing when the journal is empty', function () {
    $data = $this->getJson('/api/v1/stats/journal')->assertOk()->json('data');

    expect($data['totals']['trades'])->toBe(0)
        ->and($data['totals']['win_rate'])->toBeNull()
        ->and($data['flags'])->toBe([]);
});

it('computes win rate, expectancy and P&L from closed trades', function () {
    closedTrade($this->user, $this->stock->id, ['net_pnl' => 5000, 'r_multiple' => 1.67]);
    closedTrade($this->user, $this->stock->id, ['net_pnl' => 3000, 'r_multiple' => 1.0]);
    closedTrade($this->user, $this->stock->id, ['net_pnl' => -3000, 'r_multiple' => -1.0]);

    $totals = $this->getJson('/api/v1/stats/journal')->assertOk()->json('data.totals');

    expect($totals['trades'])->toBe(3)
        ->and($totals['wins'])->toBe(2)
        ->and($totals['win_rate'])->toBe(0.6667)
        ->and($totals['net_pnl'])->toBe(5000)
        ->and($totals['expectancy_r'])->toBe(0.5567)
        ->and($totals['sufficient_sample'])->toBeFalse();
});

it('ignores open trades: a position is not a result', function () {
    closedTrade($this->user, $this->stock->id);
    TradeJournalEntry::factory()->for($this->user)->create([
        'stock_id' => $this->stock->id,
        'status' => TradeStatus::Open,
    ]);

    expect($this->getJson('/api/v1/stats/journal')->assertOk()->json('data.totals.trades'))->toBe(1);
});

it('breaks results down by strategy and by tag', function () {
    closedTrade($this->user, $this->stock->id, ['strategy' => 'Breakout', 'tags' => ['nepse', 'momentum']]);
    closedTrade($this->user, $this->stock->id, ['strategy' => 'Breakout', 'tags' => ['nepse']]);
    closedTrade($this->user, $this->stock->id, ['strategy' => 'Pullback', 'tags' => ['patience']]);

    $data = $this->getJson('/api/v1/stats/journal')->assertOk()->json('data');

    expect($data['by_strategy'][0]['group'])->toBe('Breakout')
        ->and($data['by_strategy'][0]['trades'])->toBe(2)
        ->and(collect($data['by_tag'])->firstWhere('group', 'nepse')['trades'])->toBe(2);
});

it('compares trades where the plan was followed with trades where it was not', function () {
    closedTrade($this->user, $this->stock->id, ['followed_plan' => true, 'r_multiple' => 1.5]);
    closedTrade($this->user, $this->stock->id, ['followed_plan' => false, 'r_multiple' => -1.0, 'net_pnl' => -3000]);

    $adherence = $this->getJson('/api/v1/stats/journal')->assertOk()->json('data.plan_adherence');

    expect($adherence['answered'])->toBe(2)
        ->and($adherence['followed']['trades'])->toBe(1)
        ->and($adherence['deviated']['trades'])->toBe(1)
        ->and($adherence['followed']['expectancy_r'])->toBe(1.5)
        ->and($adherence['deviated']['expectancy_r'])->toBe(-1);
});

it('stays silent about behaviour until there are enough trades', function () {
    // Four trades, all closed early — not yet a pattern worth naming.
    for ($i = 0; $i < 4; $i++) {
        closedTrade($this->user, $this->stock->id, [
            'exit_reason' => ExitReason::Manual,
            'exit_price' => 570,
        ]);
    }

    expect($this->getJson('/api/v1/stats/journal')->assertOk()->json('data.flags'))->toBe([]);
});

it('flags winners closed by hand before the target', function () {
    for ($i = 0; $i < 5; $i++) {
        closedTrade($this->user, $this->stock->id, [
            'exit_reason' => ExitReason::Manual,
            'exit_price' => 570,   // below the 620 target
            'net_pnl' => 2000,
        ]);
    }

    $flags = collect($this->getJson('/api/v1/stats/journal')->assertOk()->json('data.flags'));
    $flag = $flags->firstWhere('key', 'early_exits');

    expect($flag)->not->toBeNull()
        ->and($flag['count'])->toBe(5)
        ->and($flag['of'])->toBe(5)
        ->and($flag['severity'])->toBe('high')
        ->and($flag['text'])->toContain('5 of 5 winners');
});

it('flags losses beyond 1R, which means the stop was not honoured', function () {
    for ($i = 0; $i < 5; $i++) {
        closedTrade($this->user, $this->stock->id, [
            'exit_price' => 500,
            'exit_reason' => ExitReason::Stop,
            'net_pnl' => -5000,
            'r_multiple' => -1.67,
        ]);
    }

    $flag = collect($this->getJson('/api/v1/stats/journal')->assertOk()->json('data.flags'))
        ->firstWhere('key', 'stops_not_honoured');

    expect($flag)->not->toBeNull()->and($flag['count'])->toBe(5);
});

it('flags trades taken against the analysis\'s own decision', function () {
    for ($i = 0; $i < 5; $i++) {
        $analysis = Analysis::factory()->for($this->user)->create(['decision' => Decision::Avoid]);
        closedTrade($this->user, $this->stock->id, ['analysis_id' => $analysis->id]);
    }

    $flag = collect($this->getJson('/api/v1/stats/journal')->assertOk()->json('data.flags'))
        ->firstWhere('key', 'traded_against_decision');

    expect($flag)->not->toBeNull()
        ->and($flag['text'])->toContain('said wait or avoid');
});

it('never mixes in another user\'s journal', function () {
    closedTrade(User::factory()->create(), $this->stock->id);

    expect($this->getJson('/api/v1/stats/journal')->assertOk()->json('data.totals.trades'))->toBe(0);
});
