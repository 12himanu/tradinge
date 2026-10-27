<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Actions\ImportPriceBars;
use App\Application\Services\SyncMerolaganiStock;
use App\Application\Services\SyncSharesansarStock;
use App\Domain\Analysis\Contracts\AnalysisEngine;
use App\Infrastructure\AI\EngineRequestSigner;
use App\Infrastructure\AI\NdjsonStreamReader;
use App\Infrastructure\AI\PythonEngineClient;
use App\Infrastructure\MarketData\CsvPriceImporter;
use App\Infrastructure\MarketData\Merolagani\MerolaganiClient;
use App\Infrastructure\MarketData\Sharesansar\SharesansarClient;
use App\Infrastructure\Storage\ChartStorage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;

/**
 * Binds domain contracts to their infrastructure implementations (docs/02-architecture.md §5).
 */
class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AnalysisEngine::class, function (Application $app): AnalysisEngine {
            $secret = config('stock_ai.engine.hmac_secret');

            $analyzeTimeout = (int) config('stock_ai.engine.analyze_timeout');

            return new PythonEngineClient(
                http: $app->make(HttpFactory::class),
                baseUrl: (string) config('stock_ai.engine.url'),
                signer: is_string($secret) && $secret !== '' ? new EngineRequestSigner($secret) : null,
                reader: new NdjsonStreamReader(
                    perReadTimeout: (int) config('stock_ai.engine.stream_read_timeout'),
                    overallTimeout: $analyzeTimeout,
                ),
                connectTimeout: (int) config('stock_ai.engine.connect_timeout'),
                healthTimeout: (int) config('stock_ai.engine.health_timeout'),
                requestTimeout: (int) config('stock_ai.engine.request_timeout'),
                analyzeTimeout: $analyzeTimeout,
            );
        });

        $this->app->bind(ChartStorage::class, fn (Application $app): ChartStorage => new ChartStorage(
            filesystems: $app->make(FilesystemFactory::class),
            images: ImageManager::usingDriver(GdDriver::class),
            disk: (string) config('stock_ai.uploads.disk'),
            maxBytes: (int) config('stock_ai.uploads.max_bytes'),
            minWidth: (int) config('stock_ai.uploads.min_width'),
            minHeight: (int) config('stock_ai.uploads.min_height'),
            maxWidth: (int) config('stock_ai.uploads.max_width'),
            maxHeight: (int) config('stock_ai.uploads.max_height'),
            mimeTypes: array_values(array_filter((array) config('stock_ai.uploads.mime_types'), is_string(...))),
        ));

        $this->app->bind(CsvPriceImporter::class, fn (): CsvPriceImporter => new CsvPriceImporter(
            maxRows: (int) config('stock_ai.market_data.csv_max_rows'),
        ));

        $this->app->bind(MerolaganiClient::class, fn (Application $app): MerolaganiClient => new MerolaganiClient(
            http: $app->make(HttpFactory::class),
            baseUrl: (string) config('stock_ai.market_data.merolagani.url'),
            timeout: (int) config('stock_ai.market_data.merolagani.timeout'),
            connectTimeout: (int) config('stock_ai.market_data.merolagani.connect_timeout'),
            retries: (int) config('stock_ai.market_data.merolagani.retries'),
            retryDelayMs: (int) config('stock_ai.market_data.merolagani.retry_delay_ms'),
            userAgent: (string) config('stock_ai.market_data.merolagani.user_agent'),
        ));

        $this->app->bind(SharesansarClient::class, fn (Application $app): SharesansarClient => new SharesansarClient(
            http: $app->make(HttpFactory::class),
            baseUrl: (string) config('stock_ai.market_data.sharesansar.url'),
            timeout: (int) config('stock_ai.market_data.sharesansar.timeout'),
            connectTimeout: (int) config('stock_ai.market_data.sharesansar.connect_timeout'),
            retries: (int) config('stock_ai.market_data.sharesansar.retries'),
            retryDelayMs: (int) config('stock_ai.market_data.sharesansar.retry_delay_ms'),
            userAgent: (string) config('stock_ai.market_data.sharesansar.user_agent'),
        ));

        $this->app->bind(SyncSharesansarStock::class, fn (Application $app): SyncSharesansarStock => new SyncSharesansarStock(
            client: $app->make(SharesansarClient::class),
            import: $app->make(ImportPriceBars::class),
            lookbackDays: (int) config('stock_ai.market_data.sharesansar.lookback_days'),
        ));

        $this->app->bind(SyncMerolaganiStock::class, fn (Application $app): SyncMerolaganiStock => new SyncMerolaganiStock(
            client: $app->make(MerolaganiClient::class),
            import: $app->make(ImportPriceBars::class),
            historyStart: CarbonImmutable::parse(
                (string) config('stock_ai.market_data.merolagani.history_start'),
                'UTC',
            )->startOfDay(),
            lookbackDays: (int) config('stock_ai.market_data.merolagani.lookback_days'),
            adjusted: (bool) config('stock_ai.market_data.merolagani.adjusted'),
        ));
    }
}
