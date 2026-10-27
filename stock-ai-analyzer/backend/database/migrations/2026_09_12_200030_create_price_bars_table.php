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
        // Real market data only. Bars digitized from screenshots live in analysis evidence, never here.
        Schema::create('price_bars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->string('timeframe', 8);
            $table->timestampTz('bar_time');
            $table->decimal('open', 14, 4);
            $table->decimal('high', 14, 4);
            $table->decimal('low', 14, 4);
            $table->decimal('close', 14, 4);
            $table->decimal('volume', 20, 2)->nullable();
            $table->decimal('turnover', 20, 2)->nullable();
            $table->string('source', 24);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['stock_id', 'timeframe', 'bar_time']);
        });

        PgCheck::in('price_bars', 'timeframe', ['15m', '1h', '4h', '1D', '1W']);
        PgCheck::in('price_bars', 'source', ['csv', 'nepse', 'yahoo']);
        PgCheck::raw(
            'price_bars',
            'price_bars_ohlc_check',
            'low > 0 AND low <= LEAST(open, close) AND high >= GREATEST(open, close)',
        );
        PgCheck::raw('price_bars', 'price_bars_volume_check', 'volume IS NULL OR volume >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('price_bars');
    }
};
