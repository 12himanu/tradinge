<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\Journal\Enums\ExitReason;
use App\Domain\Journal\Enums\TradeSide;
use App\Domain\Journal\Enums\TradeStatus;
use App\Domain\Journal\Models\TradeJournalEntry;
use App\Domain\Market\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradeJournalEntry>
 */
class TradeJournalEntryFactory extends Factory
{
    protected $model = TradeJournalEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'stock_id' => Stock::factory(),
            'side' => TradeSide::Long,
            'status' => TradeStatus::Open,
            'entry_date' => now()->subDays(3)->toDateString(),
            'entry_price' => 550,
            'quantity' => 100,
            'stop_loss' => 520,
            'target_price' => 620,
            'strategy' => 'pullback',
            'reason' => fake()->sentence(),
            'tags' => [],
            'confidence_level' => 3,
        ];
    }

    /**
     * A finished trade. The schema refuses a `closed` row without an exit, so the state supplies one.
     */
    public function closed(float $exitPrice = 600.0): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TradeStatus::Closed,
            'exit_date' => now()->toDateString(),
            'exit_price' => $exitPrice,
            'exit_reason' => ExitReason::Target,
            'net_pnl' => 0,
        ]);
    }
}
