<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The measured next-day and next-week move distributions, per scan.
 *
 * The scanner already answers "what does this chart look like". The forecast page answers the
 * question people actually arrive with — "what happens tomorrow, and this week" — and the only
 * honest form of that answer is a measurement: over this symbol's own history, how often an N-bar
 * move closed higher and where the middle 80% of those moves landed. The engine computes it
 * alongside the base rates; this is where it is kept, because it is measured on the whole import
 * and would otherwise be recomputed on every page view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_scans', function (Blueprint $table) {
            $table->jsonb('outlook')->default('[]')->after('base_rates');
        });
    }

    public function down(): void
    {
        Schema::table('market_scans', function (Blueprint $table) {
            $table->dropColumn('outlook');
        });
    }
};
