<?php

declare(strict_types=1);

use Database\Support\PgCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One engine read per stock per trading day, for the market scanner.
 *
 * Deliberately not an `analyses` row. An analysis is something the user asked for: it belongs to
 * them, it emits predictions, and those predictions are the track record Phase 6 calibrates
 * against. Scanning 582 stocks a night would file 582 predictions a night that nobody intended to
 * trade, and the calibration curve would then describe the scanner rather than the user. A scan is
 * a reference reading with no predictions attached; turning one into a real analysis stays a
 * deliberate act.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            // The trading date of the last bar, not the wall clock: re-running a scan on the same
            // data updates the row rather than filing a second opinion on the same session.
            $table->date('scanned_on');
            $table->timestampTz('as_of')->nullable();
            $table->string('timeframe', 8);
            $table->string('trading_style', 16);

            $table->decimal('last_price', 14, 4)->nullable();
            $table->string('bias', 8)->nullable();
            $table->smallInteger('setup_score')->nullable();
            $table->string('decision', 8)->nullable();
            $table->text('decision_rationale')->nullable();

            // Structure and where price sits in its own range: the two things a professional reads
            // before anything else, and the two that make a signal a trap or an opportunity.
            $table->string('structure', 8)->nullable();
            $table->string('last_event', 32)->nullable();
            $table->string('range_zone', 12)->nullable();
            $table->decimal('range_position', 5, 4)->nullable();

            $table->smallInteger('trap_count')->default(0);
            $table->smallInteger('opportunity_count')->default(0);
            $table->boolean('has_viable_plan')->default(false);

            $table->jsonb('signals')->default('[]');
            $table->jsonb('base_rates')->default('[]');
            $table->jsonb('levels')->default('[]');
            $table->jsonb('plans')->default('[]');
            $table->jsonb('factors')->default('[]');
            $table->jsonb('warnings')->default('[]');
            $table->text('report_markdown')->nullable();

            $table->integer('bar_count')->default(0);
            $table->decimal('data_quality', 4, 3)->nullable();
            $table->string('engine_version', 64)->nullable();

            // A stock the engine could not read is still a row, so a gap in the scanner is
            // explained rather than silently missing.
            $table->string('error_code', 40)->nullable();
            $table->text('error_message')->nullable();

            $table->timestampsTz();

            $table->unique(['stock_id', 'scanned_on']);
            $table->index(['scanned_on', 'setup_score']);
            $table->index(['scanned_on', 'bias']);
        });

        PgCheck::in('market_scans', 'timeframe', ['15m', '1h', '4h', '1D', '1W']);
        PgCheck::in('market_scans', 'trading_style', ['intraday', 'swing', 'positional', 'investing']);
        PgCheck::raw(
            'market_scans',
            'market_scans_bias_check',
            "bias IS NULL OR bias IN ('bullish', 'bearish', 'neutral')",
        );
        PgCheck::raw(
            'market_scans',
            'market_scans_decision_check',
            "decision IS NULL OR decision IN ('buy', 'wait', 'hold', 'sell', 'avoid')",
        );
        PgCheck::raw(
            'market_scans',
            'market_scans_setup_score_check',
            'setup_score IS NULL OR (setup_score >= 0 AND setup_score <= 100)',
        );
        PgCheck::raw(
            'market_scans',
            'market_scans_range_zone_check',
            "range_zone IS NULL OR range_zone IN ('premium', 'discount', 'equilibrium')",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('market_scans');
    }
};
