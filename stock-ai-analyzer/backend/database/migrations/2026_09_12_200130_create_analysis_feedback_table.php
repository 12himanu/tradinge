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
        Schema::create('analysis_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('analysis_id')->constrained('analyses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('rating');
            $table->string('issue_type', 24)->nullable();
            $table->text('comment')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['analysis_id', 'user_id']);
        });

        PgCheck::raw('analysis_feedback', 'analysis_feedback_rating_check', 'rating IN (-1, 1)');
        PgCheck::in('analysis_feedback', 'issue_type', [
            'wrong_symbol', 'wrong_levels', 'wrong_pattern', 'misread_chart', 'not_useful', 'helpful',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_feedback');
    }
};
