<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Domain\Analysis\Enums\PositionStatus;
use App\Domain\Chart\Models\ChartUpload;
use App\Domain\Shared\Enums\Timeframe;
use App\Domain\Shared\Enums\TradingStyle;

/**
 * The inputs one analysis run is defined by. Two runs with the same values answer the same
 * question, which is what makes reuse safe (see RequestAnalysis).
 *
 * A holding position carries both an entry price and a quantity: the database enforces it, because
 * a position without a size cannot be marked to market.
 */
final readonly class RequestAnalysisData
{
    public function __construct(
        public Timeframe $timeframe,
        public TradingStyle $tradingStyle,
        public PositionStatus $positionStatus = PositionStatus::None,
        public ?float $positionEntryPrice = null,
        public ?float $positionQuantity = null,
        public ?string $userThesis = null,
        public bool $force = false,
        /**
         * A ticker supplied at analyse time, to attach to an upload that has none.
         *
         * Uploading without a symbol leaves a chart stuck at the vision-only tier, and re-uploading
         * the same image cannot rescue it: the sha256 dedupe returns the original row and discards
         * whatever was typed the second time. So the correction has to be possible here.
         */
        public ?string $symbol = null,
        public ?string $exchange = null,
        /**
         * The user standing behind the ticker: "yes, I have looked, this chart really is that".
         *
         * It is the way past the engine's chart-identity check, which otherwise refuses a
         * screenshot it reads as another company — correctly most of the time, and not always.
         * Re-running cannot overrule it, because the same image reads the same way, so the person
         * who can actually see the chart is given the last word.
         */
        public bool $confirmSymbol = false,
    ) {}

    /**
     * Defaults taken from what the user declared at upload time.
     */
    public static function fromUpload(ChartUpload $upload): self
    {
        return new self(
            timeframe: $upload->declared_timeframe ?? Timeframe::D1,
            tradingStyle: $upload->declared_style ?? TradingStyle::Swing,
        );
    }
}
