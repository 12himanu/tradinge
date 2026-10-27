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
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exchange_id')->constrained()->restrictOnDelete();
            $table->string('symbol', 20);
            $table->string('name', 200);
            $table->string('sector', 100)->nullable();
            $table->string('instrument_type', 20)->default('equity');
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->default(new Expression("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['exchange_id', 'symbol']);
        });

        PgCheck::in('stocks', 'instrument_type', ['equity', 'mutual_fund', 'debenture', 'index', 'etf']);
        PgCheck::raw('stocks', 'stocks_symbol_upper_check', 'symbol = upper(symbol)');

        // Symbol / company-name autocomplete (docs/03-database-schema.md §3.3).
        DB::statement('CREATE INDEX stocks_symbol_trgm_idx ON stocks USING gin (symbol gin_trgm_ops)');
        DB::statement('CREATE INDEX stocks_name_trgm_idx ON stocks USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
