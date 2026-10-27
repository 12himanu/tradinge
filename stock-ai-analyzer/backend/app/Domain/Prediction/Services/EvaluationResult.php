<?php

declare(strict_types=1);

namespace App\Domain\Prediction\Services;

use App\Domain\Prediction\Enums\EvaluationMethod;
use App\Domain\Prediction\Enums\OutcomeResult;
use App\Domain\Prediction\Enums\PredictionStatus;
use App\Domain\Prediction\Models\Prediction;
use Carbon\CarbonImmutable;

/**
 * What the evaluator concluded about one prediction (P6-01).
 *
 * `result === null` means still open: the bars ran out before anything resolved, and only the
 * progress markers (`evaluatedThrough`, MFE/MAE) move.
 */
final readonly class EvaluationResult
{
    public function __construct(
        public PredictionStatus $status,
        public ?OutcomeResult $result = null,
        public EvaluationMethod $method = EvaluationMethod::Auto,
        public ?CarbonImmutable $resolvedAt = null,
        public ?CarbonImmutable $evaluatedThrough = null,
        public ?CarbonImmutable $activatedAt = null,
        public ?float $entryFill = null,
        public ?float $exitPrice = null,
        public int $barsHeld = 0,
        public ?float $rMultiple = null,
        public ?float $returnPct = null,
        /** Maximum favourable excursion, as a percentage of the fill. */
        public ?float $mfePct = null,
        /** Maximum adverse excursion, as a percentage of the fill. */
        public ?float $maePct = null,
        public bool $ambiguousBar = false,
        public ?bool $directionCorrect = null,
        public ?float $brierScore = null,
    ) {}

    /** Nothing to score yet: no bars after `as_of`. */
    public static function unchanged(
        Prediction $prediction,
        ?CarbonImmutable $evaluatedThrough = null,
    ): self {
        return new self(
            status: $prediction->status,
            evaluatedThrough: $evaluatedThrough,
            activatedAt: $prediction->activated_at,
        );
    }

    public function isResolved(): bool
    {
        return $this->result !== null;
    }

    /**
     * The row for the `outcomes` table (docs/03-database-schema.md §3.11).
     *
     * @return array<string, mixed>
     */
    public function toOutcomeAttributes(): array
    {
        return [
            'result' => $this->result,
            'evaluation_method' => $this->method,
            'triggered_at' => $this->activatedAt,
            'exit_at' => $this->resolvedAt,
            'exit_price' => $this->exitPrice,
            'return_pct' => $this->returnPct,
            'r_multiple' => $this->rMultiple,
            'mfe_pct' => $this->mfePct,
            'mae_pct' => $this->maePct,
            'bars_to_resolution' => $this->barsHeld,
            'direction_correct' => $this->directionCorrect,
            'brier_component' => $this->brierScore,
            'ambiguous_bar' => $this->ambiguousBar,
            'evaluated_through' => $this->evaluatedThrough,
        ];
    }
}
