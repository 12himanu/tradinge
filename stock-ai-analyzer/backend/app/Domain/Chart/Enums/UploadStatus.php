<?php

declare(strict_types=1);

namespace App\Domain\Chart\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum UploadStatus: string
{
    use HasValues;

    case Uploaded = 'uploaded';
    case Analyzing = 'analyzing';
    case Analyzed = 'analyzed';
    case Failed = 'failed';
    case Rejected = 'rejected';
}
