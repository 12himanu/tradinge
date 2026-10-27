<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The user's override for the engine's chart-identity check.
 *
 * That check refuses to analyse a screenshot whose title names a different company than the symbol
 * requested — which is right when it is right, and a wall with no door when a 3B vision model
 * misreads a title. Re-running cannot get past it, because the same image gives the same read. So
 * the person looking at the screenshot can say "it really is this symbol", once, per chart.
 *
 * It lives on the upload rather than the analysis because it is a fact about the image, not about
 * one run of it — and it is cleared whenever the chart is re-pointed at a different ticker, since
 * a confirmation of the old symbol says nothing about the new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_uploads', function (Blueprint $table) {
            $table->boolean('symbol_confirmed')->default(false)->after('declared_symbol');
        });
    }

    public function down(): void
    {
        Schema::table('chart_uploads', function (Blueprint $table) {
            $table->dropColumn('symbol_confirmed');
        });
    }
};
