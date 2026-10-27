<?php

declare(strict_types=1);

use Database\Support\PgCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_journal_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('analysis_id')->nullable()->constrained('analyses')->nullOnDelete();
            $table->foreignUlid('prediction_id')->nullable()->constrained('predictions')->nullOnDelete();
            $table->foreignUlid('entry_chart_upload_id')->nullable()->constrained('chart_uploads')->nullOnDelete();

            $table->string('side', 8)->default('long');
            $table->string('status', 10)->default('open');
            $table->date('entry_date');
            $table->decimal('entry_price', 14, 4);
            $table->decimal('quantity', 14, 2);
            $table->decimal('stop_loss', 14, 4)->nullable();
            $table->decimal('target_price', 14, 4)->nullable();
            $table->string('strategy', 60)->nullable();
            $table->text('reason');
            $table->jsonb('tags')->default(new Expression("'[]'::jsonb"));
            $table->smallInteger('confidence_level')->nullable();

            $table->date('exit_date')->nullable();
            $table->decimal('exit_price', 14, 4)->nullable();
            $table->string('exit_reason', 40)->nullable();
            $table->decimal('fees', 14, 2)->nullable();
            $table->decimal('gross_pnl', 16, 2)->nullable();
            $table->decimal('net_pnl', 16, 2)->nullable();
            $table->decimal('return_pct', 8, 4)->nullable();
            $table->decimal('r_multiple', 6, 2)->nullable();
            $table->boolean('followed_plan')->nullable();
            $table->text('lessons')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'entry_date']);
        });

        PgCheck::in('trade_journal_entries', 'side', ['long', 'short']);
        PgCheck::in('trade_journal_entries', 'status', ['planned', 'open', 'closed', 'cancelled']);
        PgCheck::in('trade_journal_entries', 'exit_reason', ['target', 'stop', 'manual', 'time']);
        PgCheck::raw('trade_journal_entries', 'trade_journal_entries_amounts_check', 'entry_price > 0 AND quantity > 0');
        PgCheck::raw(
            'trade_journal_entries',
            'trade_journal_entries_closed_check',
            "status <> 'closed' OR (exit_price IS NOT NULL AND exit_date IS NOT NULL)",
        );
        PgCheck::raw(
            'trade_journal_entries',
            'trade_journal_entries_long_levels_check',
            "side <> 'long' OR ((stop_loss IS NULL OR stop_loss < entry_price) AND (target_price IS NULL OR target_price > entry_price))",
        );
        PgCheck::raw(
            'trade_journal_entries',
            'trade_journal_entries_short_levels_check',
            "side <> 'short' OR ((stop_loss IS NULL OR stop_loss > entry_price) AND (target_price IS NULL OR target_price < entry_price))",
        );
        PgCheck::raw('trade_journal_entries', 'trade_journal_entries_confidence_check', 'confidence_level BETWEEN 1 AND 5');
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_journal_entries');
    }
};
