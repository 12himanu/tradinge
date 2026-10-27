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
        Schema::create('analysis_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('analysis_id')->constrained('analyses')->cascadeOnDelete();
            $table->string('type', 16);
            $table->decimal('price_low', 14, 4);
            $table->decimal('price_high', 14, 4);
            $table->decimal('strength', 4, 3);
            $table->smallInteger('touches')->nullable();
            $table->string('source', 16);
            $table->integer('pixel_y')->nullable();             // screenshot overlay position (Tier A/B)
            $table->string('evidence_id', 8)->nullable();

            $table->index('analysis_id');
        });

        PgCheck::in('analysis_levels', 'type', ['support', 'resistance', 'liquidity', 'equilibrium', 'premium', 'discount']);
        PgCheck::in('analysis_levels', 'source', ['pivots', 'volume_profile', 'smc', 'ocr_label']);
        PgCheck::raw('analysis_levels', 'analysis_levels_zone_check', 'price_low > 0 AND price_low <= price_high');
        PgCheck::raw('analysis_levels', 'analysis_levels_strength_check', 'strength BETWEEN 0 AND 1');
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_levels');
    }
};
