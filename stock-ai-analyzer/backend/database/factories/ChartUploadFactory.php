<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Chart\Enums\UploadStatus;
use App\Domain\Chart\Models\ChartUpload;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Timeframe;
use App\Domain\Shared\Enums\TradingStyle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChartUpload>
 */
class ChartUploadFactory extends Factory
{
    protected $model = ChartUpload::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ulid = (string) Str::ulid();

        return [
            'user_id' => User::factory(),
            'stock_id' => null,
            'declared_symbol' => 'HDL',
            'declared_exchange' => 'NEPSE',
            'declared_timeframe' => Timeframe::D1,
            'declared_style' => TradingStyle::Swing,
            'disk' => 'charts',
            // Same shape ChartStorage::store() returns: relative to the `charts` disk, no prefix.
            'path' => '1/'.now()->format('Y/m')."/{$ulid}.png",
            'original_filename' => 'chart.png',
            'mime_type' => 'image/png',
            'size_bytes' => fake()->numberBetween(50_000, 2_000_000),
            'width' => 1920,
            'height' => 1080,
            'sha256' => hash('sha256', $ulid),
            'platform' => null,
            'status' => UploadStatus::Uploaded,
        ];
    }
}
