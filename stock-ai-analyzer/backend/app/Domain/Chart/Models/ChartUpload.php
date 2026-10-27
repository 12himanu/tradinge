<?php

declare(strict_types=1);

namespace App\Domain\Chart\Models;

use App\Domain\Analysis\Models\Analysis;
use App\Domain\Chart\Enums\ChartPlatform;
use App\Domain\Chart\Enums\UploadStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Market\Models\Stock;
use App\Domain\Shared\Enums\Timeframe;
use App\Domain\Shared\Enums\TradingStyle;
use Carbon\CarbonImmutable;
use Database\Factories\ChartUploadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property int $user_id
 * @property int|null $stock_id
 * @property string|null $declared_symbol
 * @property bool $symbol_confirmed
 * @property string|null $declared_exchange
 * @property Timeframe|null $declared_timeframe
 * @property TradingStyle|null $declared_style
 * @property string $disk
 * @property string $path
 * @property string|null $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string $sha256
 * @property ChartPlatform|null $platform
 * @property UploadStatus $status
 * @property-read Stock|null $stock
 * @property CarbonImmutable|null $created_at
 */
#[Fillable([
    'user_id', 'stock_id', 'declared_symbol', 'symbol_confirmed', 'declared_exchange', 'declared_timeframe', 'declared_style',
    'disk', 'path', 'original_filename', 'mime_type', 'size_bytes', 'width', 'height', 'sha256', 'platform', 'status',
])]
#[UseFactory(ChartUploadFactory::class)]
class ChartUpload extends Model
{
    /** @use HasFactory<ChartUploadFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'symbol_confirmed' => 'boolean',
            'declared_timeframe' => Timeframe::class,
            'declared_style' => TradingStyle::class,
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'platform' => ChartPlatform::class,
            'status' => UploadStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /** @return HasMany<Analysis, $this> */
    public function analyses(): HasMany
    {
        return $this->hasMany(Analysis::class);
    }

    /** @return HasOne<Analysis, $this> */
    public function latestAnalysis(): HasOne
    {
        return $this->hasOne(Analysis::class)->latestOfMany();
    }
}
