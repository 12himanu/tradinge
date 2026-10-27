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
        Schema::create('analysis_indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('analysis_id')->constrained('analyses')->cascadeOnDelete();
            $table->string('key', 32);                          // ema_21, rsi_14, macd_12_26_9 ...
            $table->string('name', 16);
            $table->jsonb('params')->default(new Expression("'{}'::jsonb"));
            $table->decimal('value', 18, 6)->nullable();
            $table->jsonb('components')->nullable();
            $table->string('signal', 8)->nullable();
            $table->string('status', 12);
            $table->string('unavailable_reason', 200)->nullable();
            $table->string('evidence_id', 8)->nullable();

            $table->unique(['analysis_id', 'key']);
        });

        PgCheck::in('analysis_indicators', 'signal', ['bullish', 'bearish', 'neutral']);
        PgCheck::in('analysis_indicators', 'status', ['computed', 'vision_read', 'unavailable']);
        PgCheck::raw(
            'analysis_indicators',
            'analysis_indicators_unavailable_check',
            "status <> 'unavailable' OR (value IS NULL AND unavailable_reason IS NOT NULL)",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_indicators');
    }
};
