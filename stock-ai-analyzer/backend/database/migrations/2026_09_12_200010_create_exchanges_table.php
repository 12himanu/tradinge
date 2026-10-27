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
        Schema::create('exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 120);
            $table->char('country', 2);
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->jsonb('trading_days');                       // ISO weekdays, e.g. NEPSE Sun–Thu [7,1,2,3,4]
            $table->time('session_open');
            $table->time('session_close');
            $table->decimal('price_band_pct', 5, 2)->nullable();
            $table->decimal('tick_size', 10, 4);
            $table->unsignedInteger('lot_size')->default(1);
            $table->boolean('allows_short_selling')->default(false);
            $table->jsonb('fee_schedule')->default(new Expression("'{}'::jsonb"));
            $table->jsonb('holidays')->default(new Expression("'[]'::jsonb"));
            $table->timestampsTz();
        });

        PgCheck::raw('exchanges', 'exchanges_code_upper_check', 'code = upper(code)');
        PgCheck::raw('exchanges', 'exchanges_tick_size_check', 'tick_size > 0');
        PgCheck::raw('exchanges', 'exchanges_lot_size_check', 'lot_size > 0');
        PgCheck::raw('exchanges', 'exchanges_session_check', 'session_close > session_open');
    }

    public function down(): void
    {
        Schema::dropIfExists('exchanges');
    }
};
