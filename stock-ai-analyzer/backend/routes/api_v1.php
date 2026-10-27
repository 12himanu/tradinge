<?php

declare(strict_types=1);

use App\Presentation\Http\Controllers\Api\V1\AnalysisController;
use App\Presentation\Http\Controllers\Api\V1\AuthController;
use App\Presentation\Http\Controllers\Api\V1\ChartUploadController;
use App\Presentation\Http\Controllers\Api\V1\HealthController;
use App\Presentation\Http\Controllers\Api\V1\MarketDataController;
use App\Presentation\Http\Controllers\Api\V1\MarketScanController;
use App\Presentation\Http\Controllers\Api\V1\MeController;
use App\Presentation\Http\Controllers\Api\V1\PredictionController;
use App\Presentation\Http\Controllers\Api\V1\PriceBarController;
use App\Presentation\Http\Controllers\Api\V1\StatsController;
use App\Presentation\Http\Controllers\Api\V1\StockController;
use App\Presentation\Http\Controllers\Api\V1\TradeJournalController;
use Illuminate\Support\Facades\Route;

// Prefix /api/v1 is configured in bootstrap/app.php. Contract: docs/05-api-spec.md.

Route::get('health', HealthController::class)->name('api.health');

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('api.auth.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::get('me', MeController::class)->name('api.me');
    Route::patch('me/settings', [MeController::class, 'updateSettings'])->name('api.me.settings');

    // Running the scraper on demand, and what it is doing.
    Route::get('market-data/status', [MarketDataController::class, 'status'])->name('api.market-data.status');
    Route::post('market-data/sync', [MarketDataController::class, 'sync'])
        ->middleware('throttle:6,1')->name('api.market-data.sync');
    Route::post('market-data/scan', [MarketDataController::class, 'scan'])
        ->middleware('throttle:6,1')->name('api.market-data.scan');

    // The market scanner: shared reference readings, like price bars.
    Route::get('market-scan', [MarketScanController::class, 'index'])->name('api.market-scan.index');
    // Before the {stock} route, which is numeric-only, so the two can never collide.
    Route::get('market-scan/hotlist', [MarketScanController::class, 'hotlist'])->name('api.market-scan.hotlist');
    Route::get('market-scan/{stock}', [MarketScanController::class, 'show'])
        ->whereNumber('stock')->name('api.market-scan.show');

    Route::get('stocks', [StockController::class, 'index'])->name('api.stocks.index');
    Route::get('stocks/symbol/{symbol}', [StockController::class, 'showBySymbol'])->name('api.stocks.by-symbol');
    Route::get('stocks/{stock}', [StockController::class, 'show'])->whereNumber('stock')->name('api.stocks.show');
    Route::get('stocks/{stock}/prices', [PriceBarController::class, 'index'])->whereNumber('stock')->name('api.stocks.prices');
    Route::post('stocks/{stock}/prices/import', [PriceBarController::class, 'import'])
        ->whereNumber('stock')
        ->middleware('throttle:upload')
        ->name('api.stocks.prices.import');

    Route::post('charts/upload', [ChartUploadController::class, 'upload'])
        ->middleware('throttle:upload')
        ->name('api.charts.upload');
    Route::get('charts/{chartUpload}', [ChartUploadController::class, 'show'])->whereUlid('chartUpload')->name('api.charts.show');
    Route::get('charts/{chartUpload}/image', [ChartUploadController::class, 'image'])->whereUlid('chartUpload')->name('api.charts.image');
    Route::delete('charts/{chartUpload}', [ChartUploadController::class, 'destroy'])->whereUlid('chartUpload')->name('api.charts.destroy');

    Route::post('charts/{chartUpload}/analyze', [AnalysisController::class, 'store'])
        ->whereUlid('chartUpload')
        ->middleware('throttle:analyze')
        ->name('api.charts.analyze');

    Route::get('history', [AnalysisController::class, 'history'])->name('api.history');
    Route::get('analysis/{analysis}', [AnalysisController::class, 'show'])->whereUlid('analysis')->name('api.analysis.show');
    Route::get('analysis/{analysis}/status', [AnalysisController::class, 'status'])->whereUlid('analysis')->name('api.analysis.status');
    Route::post('analysis/{analysis}/cancel', [AnalysisController::class, 'cancel'])->whereUlid('analysis')->name('api.analysis.cancel');
    Route::post('analysis/{analysis}/feedback', [AnalysisController::class, 'feedback'])->whereUlid('analysis')->name('api.analysis.feedback');
    Route::get('analysis/{analysis}/price-path', [AnalysisController::class, 'pricePath'])->whereUlid('analysis')->name('api.analysis.price-path');

    Route::get('predictions', [PredictionController::class, 'index'])->name('api.predictions.index');
    Route::get('predictions/{prediction}', [PredictionController::class, 'show'])->whereUlid('prediction')->name('api.predictions.show');
    Route::post('predictions/{prediction}/cancel', [PredictionController::class, 'cancel'])->whereUlid('prediction')->name('api.predictions.cancel');

    Route::get('trades', [TradeJournalController::class, 'index'])->name('api.trades.index');
    Route::post('trades', [TradeJournalController::class, 'store'])->name('api.trades.store');
    Route::get('trades/{trade}', [TradeJournalController::class, 'show'])->whereUlid('trade')->name('api.trades.show');
    Route::patch('trades/{trade}', [TradeJournalController::class, 'update'])->whereUlid('trade')->name('api.trades.update');
    Route::post('trades/{trade}/close', [TradeJournalController::class, 'close'])->whereUlid('trade')->name('api.trades.close');
    Route::delete('trades/{trade}', [TradeJournalController::class, 'destroy'])->whereUlid('trade')->name('api.trades.destroy');

    Route::get('stats/performance', [StatsController::class, 'performance'])->name('api.stats.performance');
    Route::get('stats/journal', [StatsController::class, 'journal'])->name('api.stats.journal');
    Route::get('stats/calibration', [StatsController::class, 'calibration'])->name('api.stats.calibration');
});
