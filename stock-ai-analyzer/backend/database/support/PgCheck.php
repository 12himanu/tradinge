<?php

declare(strict_types=1);

namespace Database\Support;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL CHECK constraints for migrations (ADR-14: varchar + CHECK instead of native enums).
 *
 * Allowed values are written literally in each migration so historical migrations never change
 * when PHP enums evolve; tests/Feature/Database/SchemaTest.php verifies they stay in sync.
 */
final class PgCheck
{
    /**
     * @param  list<string>  $values
     */
    public static function in(string $table, string $column, array $values): void
    {
        $quoted = implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $values,
        ));

        self::raw($table, "{$table}_{$column}_check", "{$column} IN ({$quoted})");
    }

    public static function raw(string $table, string $name, string $expression): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
    }
}
