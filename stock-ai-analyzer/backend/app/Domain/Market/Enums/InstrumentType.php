<?php

declare(strict_types=1);

namespace App\Domain\Market\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum InstrumentType: string
{
    use HasValues;

    case Equity = 'equity';
    case MutualFund = 'mutual_fund';
    case Debenture = 'debenture';
    case Index = 'index';
    case Etf = 'etf';
}
