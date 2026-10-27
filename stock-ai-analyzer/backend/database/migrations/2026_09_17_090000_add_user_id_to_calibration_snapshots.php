<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calibration is per person (P6-10).
 *
 * The score → hit-rate mapping is learned from one trader's resolved predictions, on the symbols
 * and timeframes they actually trade. Pooling two users' outcomes into one curve would calibrate
 * each of them against the other's market, so the snapshot carries its owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_snapshots', function (Blueprint $table) {
            $table->foreignId('user_id')->after('id')->constrained()->cascadeOnDelete();
            $table->index(['user_id', 'engine_version', 'scope', 'created_at'], 'calibration_snapshots_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('calibration_snapshots', function (Blueprint $table) {
            $table->dropIndex('calibration_snapshots_lookup_index');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
