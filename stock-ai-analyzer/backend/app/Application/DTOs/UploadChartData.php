<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Domain\Analysis\Enums\PositionStatus;
use App\Domain\Shared\Enums\Timeframe;
use App\Domain\Shared\Enums\TradingStyle;
use Illuminate\Http\UploadedFile;

/**
 * Validated input of POST /charts/upload. Position and thesis are carried for the analysis request
 * (Phase 4D); chart_uploads itself stores only the declared chart context.
 */
final readonly class UploadChartData
{
    public function __construct(
        public UploadedFile $image,
        public ?string $originalFilename,
        public TradingStyle $tradingStyle,
        public ?string $symbol,
        public ?string $exchange,
        public ?Timeframe $timeframe,
        public PositionStatus $positionStatus,
        public ?string $positionEntryPrice,
        public ?string $positionQuantity,
        public ?string $thesis,
        public bool $autoAnalyze,
    ) {}
}
