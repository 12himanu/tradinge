<?php

declare(strict_types=1);

use Database\Support\PgCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE price_bars DROP CONSTRAINT price_bars_source_check');
        PgCheck::in('price_bars', 'source', ['csv', 'nepse', 'yahoo', 'merolagani']);
    }

    public function down(): void
    {
        DB::table('price_bars')->where('source', 'merolagani')->delete();

        DB::statement('ALTER TABLE price_bars DROP CONSTRAINT price_bars_source_check');
        PgCheck::in('price_bars', 'source', ['csv', 'nepse', 'yahoo']);
    }
};
