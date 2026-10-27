<?php

declare(strict_types=1);

use Database\Support\PgCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analyses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('chart_upload_id')->constrained('chart_uploads');
            $table->foreignId('stock_id')->nullable()->constrained()->nullOnDelete();

            $table->string('timeframe', 8);
            $table->string('trading_style', 16);
            $table->string('position_status', 8)->default('none');
            $table->decimal('position_entry_price', 14, 4)->nullable();
            $table->decimal('position_quantity', 14, 2)->nullable();
            $table->text('user_thesis')->nullable();

            $table->string('status', 12)->default('queued');
            $table->string('current_stage', 32)->nullable();

            $table->string('data_tier', 12)->nullable();
            $table->decimal('data_quality', 4, 3)->nullable();
            $table->timestampTz('as_of')->nullable();
            $table->decimal('last_price', 14, 4)->nullable();

            $table->string('bias', 8)->nullable();
            $table->smallInteger('setup_score')->nullable();
            $table->decimal('calibrated_probability', 4, 3)->nullable();
            $table->string('decision', 8)->nullable();
            $table->boolean('decision_limited')->default(false);
            $table->string('narrative_source', 12)->nullable();

            $table->jsonb('report')->nullable();
            $table->text('report_markdown')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->jsonb('warnings')->default(new Expression("'[]'::jsonb"));

            $table->string('engine_version', 64)->nullable();
            $table->string('prompt_version', 64)->nullable();
            $table->jsonb('models')->nullable();

            $table->string('error_code', 40)->nullable();
            $table->text('error_message')->nullable();

            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'created_at']);
            $table->index(['stock_id', 'created_at']);
            $table->index('decision');
        });

        PgCheck::in('analyses', 'timeframe', ['15m', '1h', '4h', '1D', '1W']);
        PgCheck::in('analyses', 'trading_style', ['swing', 'positional', 'investing', 'intraday']);
        PgCheck::in('analyses', 'position_status', ['none', 'holding']);
        PgCheck::in('analyses', 'status', ['queued', 'running', 'completed', 'partial', 'failed', 'cancelled']);
        PgCheck::in('analyses', 'data_tier', ['ohlcv', 'digitized', 'vision_only']);
        PgCheck::in('analyses', 'bias', ['bullish', 'bearish', 'neutral']);
        PgCheck::in('analyses', 'decision', ['buy', 'wait', 'hold', 'sell', 'avoid']);
        PgCheck::in('analyses', 'narrative_source', ['llm', 'template']);
        PgCheck::raw('analyses', 'analyses_setup_score_check', 'setup_score BETWEEN 0 AND 100');
        PgCheck::raw('analyses', 'analyses_data_quality_check', 'data_quality BETWEEN 0 AND 1');
        PgCheck::raw('analyses', 'analyses_probability_check', 'calibrated_probability BETWEEN 0 AND 1');
        PgCheck::raw(
            'analyses',
            'analyses_holding_position_check',
            // COALESCE: a NULL comparison makes the CHECK evaluate to NULL, which Postgres accepts.
            "position_status = 'none' OR (COALESCE(position_entry_price, 0) > 0 AND COALESCE(position_quantity, 0) > 0)",
        );

        DB::statement(
            "CREATE INDEX analyses_active_status_idx ON analyses (status) WHERE status IN ('queued', 'running')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('analyses');
    }
};
