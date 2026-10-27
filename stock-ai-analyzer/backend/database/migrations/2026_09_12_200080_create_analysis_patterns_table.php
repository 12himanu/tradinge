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
        // Classic chart patterns, candlestick patterns and SMC structures (BOS, CHoCH, OB, FVG, ...).
        Schema::create('analysis_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('analysis_id')->constrained('analyses')->cascadeOnDelete();
            $table->string('category', 12);
            $table->string('type', 40);
            $table->string('direction', 8);
            $table->string('status', 12);
            $table->decimal('confidence', 4, 3);
            $table->string('detection_method', 12);
            $table->timestampTz('start_time')->nullable();
            $table->timestampTz('end_time')->nullable();
            $table->decimal('price_low', 14, 4)->nullable();
            $table->decimal('price_high', 14, 4)->nullable();
            $table->jsonb('key_points')->nullable();
            $table->string('confirmation_rule', 300)->nullable();
            $table->decimal('measured_target', 14, 4)->nullable();
            $table->decimal('invalidation_price', 14, 4)->nullable();
            $table->string('evidence_id', 8)->nullable();

            $table->index('analysis_id');
            $table->index(['category', 'type']);
        });

        PgCheck::in('analysis_patterns', 'category', ['classic', 'candlestick', 'smc']);
        PgCheck::in('analysis_patterns', 'direction', ['bullish', 'bearish', 'neutral']);
        PgCheck::in('analysis_patterns', 'status', ['forming', 'confirmed', 'failed', 'mitigated', 'unmitigated']);
        PgCheck::in('analysis_patterns', 'detection_method', ['algorithmic', 'vision', 'both']);
        PgCheck::raw('analysis_patterns', 'analysis_patterns_confidence_check', 'confidence BETWEEN 0 AND 1');
        PgCheck::raw('analysis_patterns', 'analysis_patterns_zone_check', 'price_low IS NULL OR price_high IS NULL OR price_low <= price_high');
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_patterns');
    }
};
