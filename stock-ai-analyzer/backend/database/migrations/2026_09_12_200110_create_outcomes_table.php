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
        Schema::create('outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('prediction_id')->unique()->constrained('predictions')->restrictOnDelete();
            $table->string('result', 16);
            $table->string('evaluation_method', 8);
            $table->timestampTz('triggered_at')->nullable();
            $table->timestampTz('exit_at')->nullable();
            $table->decimal('exit_price', 14, 4)->nullable();
            $table->decimal('return_pct', 8, 4)->nullable();
            $table->decimal('r_multiple', 6, 2)->nullable();
            $table->decimal('mfe_pct', 8, 4)->nullable();
            $table->decimal('mae_pct', 8, 4)->nullable();
            $table->smallInteger('bars_to_resolution')->nullable();
            $table->boolean('direction_correct')->nullable();
            $table->decimal('brier_component', 6, 5)->nullable();
            $table->boolean('ambiguous_bar')->default(false);   // stop and target hit in the same bar → counted as loss
            $table->timestampTz('evaluated_through');
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        PgCheck::in('outcomes', 'result', ['win', 'loss', 'breakeven', 'expired', 'not_triggered', 'invalidated', 'no_data']);
        PgCheck::in('outcomes', 'evaluation_method', ['auto', 'manual']);
        PgCheck::raw('outcomes', 'outcomes_brier_check', 'brier_component BETWEEN 0 AND 1');
    }

    public function down(): void
    {
        Schema::dropIfExists('outcomes');
    }
};
