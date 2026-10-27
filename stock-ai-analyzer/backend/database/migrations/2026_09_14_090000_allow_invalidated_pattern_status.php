<?php

declare(strict_types=1);

use Database\Support\PgCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SMC order blocks and fair value gaps have a third end state: price closed through the zone, so
 * it is invalidated rather than merely mitigated (docs/04 §5.5). Phase 4D persists those events.
 */
return new class extends Migration
{
    private const array STATUSES = ['forming', 'confirmed', 'failed', 'mitigated', 'unmitigated', 'invalidated'];

    public function up(): void
    {
        DB::statement('ALTER TABLE analysis_patterns DROP CONSTRAINT analysis_patterns_status_check');
        PgCheck::in('analysis_patterns', 'status', self::STATUSES);
    }

    public function down(): void
    {
        DB::statement("DELETE FROM analysis_patterns WHERE status = 'invalidated'");
        DB::statement('ALTER TABLE analysis_patterns DROP CONSTRAINT analysis_patterns_status_check');
        PgCheck::in('analysis_patterns', 'status', array_values(array_diff(self::STATUSES, ['invalidated'])));
    }
};
