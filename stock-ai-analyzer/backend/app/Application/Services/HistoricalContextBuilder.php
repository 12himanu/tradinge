<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Analysis\Models\Analysis;

/**
 * Similar past setups, passed to the engine as evidence (P4D-06, docs/04 §10).
 *
 * Deliberately empty until Phase 6: there are no recorded outcomes to summarise yet, and a
 * confident-sounding statistic drawn from nothing would be worse than silence. The wiring exists
 * now so Phase 6 only has to fill in the query.
 *
 * @phpstan-type ContextItem array{key: string, text: string, value: float|null, unit: string, sample_size: int}
 */
final readonly class HistoricalContextBuilder
{
    /**
     * @return list<ContextItem>
     */
    public function build(Analysis $analysis): array
    {
        return [];
    }
}
