<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Domain\Chart\Models\ChartUpload;

final readonly class UploadChartResult
{
    public function __construct(
        public ChartUpload $upload,
        public bool $deduplicated,
    ) {}
}
