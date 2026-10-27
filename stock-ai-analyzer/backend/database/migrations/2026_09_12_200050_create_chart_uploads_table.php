<?php

declare(strict_types=1);

use Database\Support\PgCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_uploads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->nullable()->constrained()->nullOnDelete();
            $table->string('declared_symbol', 20)->nullable();
            $table->string('declared_exchange', 16)->nullable();
            $table->string('declared_timeframe', 8)->nullable();
            $table->string('declared_style', 16)->nullable();
            $table->string('disk', 32);
            $table->string('path', 512);
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 64);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->char('sha256', 64);
            $table->string('platform', 24)->nullable();
            $table->string('status', 16)->default('uploaded');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'created_at']);
        });

        PgCheck::in('chart_uploads', 'declared_timeframe', ['15m', '1h', '4h', '1D', '1W']);
        PgCheck::in('chart_uploads', 'declared_style', ['swing', 'positional', 'investing', 'intraday']);
        PgCheck::in('chart_uploads', 'platform', ['tradingview', 'nepse_chart', 'other']);
        PgCheck::in('chart_uploads', 'status', ['uploaded', 'analyzing', 'analyzed', 'failed', 'rejected']);
        PgCheck::raw('chart_uploads', 'chart_uploads_dimensions_check', 'size_bytes > 0 AND width > 0 AND height > 0');

        // Dedupe per user, ignoring soft-deleted uploads.
        DB::statement(
            'CREATE UNIQUE INDEX chart_uploads_user_sha256_unique ON chart_uploads (user_id, sha256) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_uploads');
    }
};
