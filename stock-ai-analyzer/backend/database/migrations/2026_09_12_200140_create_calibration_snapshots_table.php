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
        Schema::create('calibration_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('engine_version', 64);
            $table->string('scope', 40);                        // global, style:swing, pattern:ascending_triangle
            $table->string('method', 16);
            $table->unsignedInteger('sample_size');
            $table->decimal('brier_score', 6, 5)->nullable();
            $table->jsonb('buckets')->default(new Expression("'[]'::jsonb"));
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['engine_version', 'scope']);
        });

        PgCheck::in('calibration_snapshots', 'method', ['isotonic', 'binned']);
        PgCheck::raw('calibration_snapshots', 'calibration_snapshots_brier_check', 'brier_score BETWEEN 0 AND 1');
    }

    public function down(): void
    {
        Schema::dropIfExists('calibration_snapshots');
    }
};
