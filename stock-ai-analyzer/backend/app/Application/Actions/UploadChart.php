<?php

declare(strict_types=1);

namespace App\Application\Actions;

use App\Application\DTOs\UploadChartData;
use App\Application\DTOs\UploadChartResult;
use App\Domain\Chart\Enums\UploadStatus;
use App\Domain\Chart\Models\ChartUpload;
use App\Domain\Identity\Models\User;
use App\Domain\Market\Services\StockResolver;
use App\Infrastructure\Storage\ChartStorage;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * P3-05: validate and re-encode → dedupe per user → store privately → create the ChartUpload.
 *
 * `auto_analyze` is validated but not acted on yet: queuing the analysis (RequestAnalysis) is wired up
 * in Phase 4D, so the response's `analysis` is always null for now.
 */
final class UploadChart
{
    public function __construct(
        private readonly ChartStorage $storage,
        private readonly StockResolver $stocks,
    ) {}

    public function __invoke(User $user, UploadChartData $data): UploadChartResult
    {
        $chart = $this->storage->prepare($data->image);

        $exchange = $data->exchange ?? $user->loadMissing('defaultExchange')->defaultExchange?->code;

        $existing = $this->existingUpload($user, $chart->sha256);
        if ($existing !== null) {
            $this->relabel($existing, $exchange, $data->symbol);

            return new UploadChartResult($existing, deduplicated: true);
        }

        $upload = new ChartUpload([
            'user_id' => $user->id,
            'stock_id' => $this->stocks->resolve($exchange, $data->symbol)?->id,
            'declared_symbol' => $data->symbol,
            'declared_exchange' => $exchange,
            'declared_timeframe' => $data->timeframe,
            'declared_style' => $data->tradingStyle,
            'disk' => $this->storage->diskName(),
            'original_filename' => $data->originalFilename,
            'mime_type' => $chart->mimeType,
            'size_bytes' => $chart->sizeBytes(),
            'width' => $chart->width,
            'height' => $chart->height,
            'sha256' => $chart->sha256,
            'status' => UploadStatus::Uploaded,
        ]);
        $upload->id = (string) Str::ulid();
        $upload->path = $this->storage->store($chart, $user->id, $upload->id, CarbonImmutable::now());

        try {
            // Own savepoint, so losing the dedupe race below doesn't abort an enclosing transaction.
            DB::transaction(fn () => $upload->save());
        } catch (UniqueConstraintViolationException) {
            // A concurrent upload of the same image won: keep its row and drop our copy of the file.
            $this->storage->delete($upload->path);

            $winner = $this->existingUpload($user, $chart->sha256)
                ?? throw new LogicException('Dedupe conflict without a matching upload.');
            $this->relabel($winner, $exchange, $data->symbol);

            return new UploadChartResult($winner, deduplicated: true);
        } catch (Throwable $e) {
            $this->storage->delete($upload->path);

            throw $e;
        }

        return new UploadChartResult($upload, deduplicated: false);
    }

    /**
     * Re-point a deduplicated upload at the ticker just declared for it.
     *
     * The dedupe used to hand back the stored row untouched, which quietly discarded the symbol
     * typed the second time: upload a chart as the wrong ticker, notice, upload it again with the
     * right one, and the app returns the original row and analyses the wrong instrument again —
     * with a "you already uploaded this chart" notice that says nothing about the symbol. The
     * image is the same; which instrument the user says it is, is not, and the later statement is
     * the one they are looking at right now.
     *
     * Only an explicit, resolvable symbol moves it. An unknown ticker names nothing to re-point at.
     */
    private function relabel(ChartUpload $upload, ?string $exchange, ?string $symbol): void
    {
        if ($symbol === null) {
            return;
        }

        $stock = $this->stocks->resolve($exchange, $symbol);
        if ($stock === null || $stock->id === $upload->stock_id) {
            return;
        }

        $upload->forceFill([
            'stock_id' => $stock->id,
            'declared_symbol' => $stock->symbol,
            // Vouching for the old ticker says nothing about this one.
            'symbol_confirmed' => false,
        ])->save();
    }

    private function existingUpload(User $user, string $sha256): ?ChartUpload
    {
        return ChartUpload::query()->whereBelongsTo($user)->where('sha256', $sha256)->first();
    }
}
