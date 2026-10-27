<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much price data an analysis was built on.
 *
 * Analyses are cached and reused for identical inputs, but "identical inputs" was measured only by
 * the chart, timeframe, style and position — none of which change when a CSV import arrives. So a
 * Tier C run ("no bars, no numbers") kept being handed back after the user imported the very data
 * that would have made it a Tier A analysis.
 *
 * `as_of` alone does not close the hole: back-filling older history leaves the latest bar unchanged
 * while changing what can be computed (EMA 200 appears at 200 bars). The count is the other half of
 * the fingerprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->unsignedInteger('price_bar_count')->nullable()->after('as_of');
        });
    }

    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->dropColumn('price_bar_count');
        });
    }
};
