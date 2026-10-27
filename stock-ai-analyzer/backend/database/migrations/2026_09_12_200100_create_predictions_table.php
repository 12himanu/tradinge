<?php

declare(strict_types=1);

use Database\Support\PgCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Machine-checkable statements emitted by an analysis. Immutable apart from status:
        // the analysis FK has no ON DELETE action, so an analysis with predictions cannot be hard-deleted.
        Schema::create('predictions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('analysis_id')->constrained('analyses');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->nullable()->constrained()->nullOnDelete();

            $table->string('kind', 20);
            $table->string('scenario', 12);
            $table->string('direction', 8);
            $table->string('timeframe', 8);
            $table->string('trigger_type', 16);
            $table->decimal('trigger_price', 14, 4)->nullable();
            $table->decimal('entry_price', 14, 4)->nullable();
            $table->decimal('stop_price', 14, 4)->nullable();
            $table->decimal('target_price_1', 14, 4)->nullable();
            $table->decimal('target_price_2', 14, 4)->nullable();
            $table->decimal('invalidation_price', 14, 4)->nullable();
            $table->decimal('reference_price', 14, 4);
            $table->timestampTz('as_of');
            $table->smallInteger('horizon_bars');
            $table->timestampTz('expires_at');
            $table->smallInteger('setup_score')->nullable();
            $table->decimal('probability', 4, 3)->nullable();
            $table->decimal('risk_reward', 6, 2)->nullable();
            $table->string('status', 12)->default('pending');
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->string('engine_version', 64);
            $table->timestampsTz();

            $table->index(['status', 'expires_at']);
            $table->index(['stock_id', 'status']);
            $table->index('analysis_id');
        });

        PgCheck::in('predictions', 'kind', ['directional_bias', 'trade_setup']);
        PgCheck::in('predictions', 'scenario', ['primary', 'bullish', 'bearish']);
        PgCheck::in('predictions', 'direction', ['long', 'short', 'flat']);
        PgCheck::in('predictions', 'timeframe', ['15m', '1h', '4h', '1D', '1W']);
        PgCheck::in('predictions', 'trigger_type', ['immediate', 'close_above', 'close_below', 'touch']);
        PgCheck::in('predictions', 'status', ['pending', 'active', 'target_hit', 'stopped', 'expired', 'invalidated', 'cancelled']);

        PgCheck::raw('predictions', 'predictions_trigger_price_check', "trigger_type = 'immediate' OR trigger_price IS NOT NULL");
        PgCheck::raw(
            'predictions',
            'predictions_setup_prices_check',
            "kind <> 'trade_setup' OR (entry_price IS NOT NULL AND stop_price IS NOT NULL AND target_price_1 IS NOT NULL)",
        );
        PgCheck::raw(
            'predictions',
            'predictions_long_order_check',
            "kind <> 'trade_setup' OR direction <> 'long' OR (stop_price < entry_price AND entry_price < target_price_1)",
        );
        PgCheck::raw(
            'predictions',
            'predictions_short_order_check',
            "kind <> 'trade_setup' OR direction <> 'short' OR (stop_price > entry_price AND entry_price > target_price_1)",
        );
        PgCheck::raw('predictions', 'predictions_reference_price_check', 'reference_price > 0');
        PgCheck::raw('predictions', 'predictions_horizon_check', 'horizon_bars > 0 AND expires_at > as_of');
        PgCheck::raw('predictions', 'predictions_setup_score_check', 'setup_score BETWEEN 0 AND 100');
        PgCheck::raw('predictions', 'predictions_probability_check', 'probability BETWEEN 0 AND 1');
    }

    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
