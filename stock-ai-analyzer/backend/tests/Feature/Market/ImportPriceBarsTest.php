<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Market\Models\Exchange;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Models\Stock;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());

    $this->nepse = Exchange::factory()->create(['code' => 'NEPSE']);   // trades Sunday–Thursday
    $this->stock = Stock::factory()->for($this->nepse)->create(['symbol' => 'HDL']);
});

function priceCsv(string $contents): UploadedFile
{
    return UploadedFile::fake()->createWithContent('hdl.csv', $contents);
}

const THREE_DAYS = <<<'CSV'
    date,open,high,low,close,volume
    2026-09-06,550,560,545,558,12450
    2026-09-07,558,570,555,566,15000
    2026-09-08,566,575,560,570,9000
    CSV;

it('imports bars and reports every row it skipped', function () {
    $csv = <<<'CSV'
        date,open,high,low,close,volume
        2026-09-06,550,560,545,558,12450
        2026-09-07,558,570,555,566,15000
        2026-09-07,558,570,555,566,15000
        2026-09-11,566,575,560,570,9000
        2026-09-08,566,560,560,570,9000
        CSV;

    $this->postJson("/api/v1/stocks/{$this->stock->id}/prices/import", ['file' => priceCsv($csv), 'timeframe' => '1D'])
        ->assertOk()
        ->assertJsonPath('data.inserted', 2)
        ->assertJsonPath('data.updated', 0)
        ->assertJsonPath('data.skipped_total', 3)
        ->assertJsonPath('data.skipped', [
            ['row' => 4, 'reason' => 'Duplicate date 2026-09-07 (first seen on row 3).'],
            ['row' => 5, 'reason' => 'Fri 11 Sep 2026 is not a NEPSE trading day.'],
            ['row' => 6, 'reason' => 'High 560 is below the open or close.'],
        ])
        ->assertJsonPath('data.source.date_format', 'iso')
        ->assertJsonPath('data.coverage', [
            ['timeframe' => '1D', 'from' => '2026-09-06T00:00:00Z', 'to' => '2026-09-07T00:00:00Z', 'bars' => 2],
        ]);

    $bar = PriceBar::query()->where('stock_id', $this->stock->id)->orderBy('bar_time')->firstOrFail();
    expect(PriceBar::query()->count())->toBe(2)
        ->and($bar->close)->toBe('558.0000')
        ->and($bar->volume)->toBe('12450.00')
        ->and($bar->source->value)->toBe('csv');
});

it('is idempotent: re-importing updates rows in place', function () {
    $uri = "/api/v1/stocks/{$this->stock->id}/prices/import";
    $this->postJson($uri, ['file' => priceCsv(THREE_DAYS), 'timeframe' => '1D'])->assertJsonPath('data.inserted', 3);

    $corrected = str_replace('2026-09-08,566,575,560,570', '2026-09-08,566,575,560,572', THREE_DAYS);
    $this->postJson($uri, ['file' => priceCsv($corrected), 'timeframe' => '1D'])
        ->assertOk()
        ->assertJsonPath('data.inserted', 0)
        ->assertJsonPath('data.updated', 3);

    expect(PriceBar::query()->count())->toBe(3)
        ->and(PriceBar::query()->orderByDesc('bar_time')->firstOrFail()->close)->toBe('572.0000');
});

it('keeps a turnover the new source does not carry', function () {
    // Merolagani reports no traded amount. Assigning `excluded.turnover` outright would mean the
    // nightly sync silently deleted turnover a CSV had already supplied.
    $this->postJson("/api/v1/stocks/{$this->stock->id}/prices/import", [
        'timeframe' => '1D',
        'file' => priceCsv(<<<'CSV'
            date,open,high,low,close,volume,turnover
            2026-09-07,558,570,555,566,15000,8490000
            CSV),
    ])->assertOk();

    // The same bar again, from a source with volume but no turnover.
    $this->postJson("/api/v1/stocks/{$this->stock->id}/prices/import", [
        'timeframe' => '1D',
        'file' => priceCsv(<<<'CSV'
            date,open,high,low,close,volume
            2026-09-07,558,571,555,567,16000
            CSV),
    ])->assertOk();

    $bar = PriceBar::query()->where('stock_id', $this->stock->id)->sole();

    expect((float) $bar->turnover)->toBe(8490000.0)
        ->and((float) $bar->close)->toBe(567.0)
        ->and((float) $bar->high)->toBe(571.0)
        ->and((float) $bar->volume)->toBe(16000.0);
});

it('still lets a correction lower a high', function () {
    // COALESCE guards absent values, not smaller ones: OHLC is always the provider's claim.
    $this->postJson("/api/v1/stocks/{$this->stock->id}/prices/import", [
        'timeframe' => '1D',
        'file' => priceCsv(<<<'CSV'
            date,open,high,low,close,volume
            2026-09-07,558,590,555,566,15000
            CSV),
    ])->assertOk();

    $this->postJson("/api/v1/stocks/{$this->stock->id}/prices/import", [
        'timeframe' => '1D',
        'file' => priceCsv(<<<'CSV'
            date,open,high,low,close,volume
            2026-09-07,558,570,555,566,15000
            CSV),
    ])->assertOk();

    expect((float) PriceBar::query()->where('stock_id', $this->stock->id)->sole()->high)->toBe(570.0);
});

it('rejects a file it cannot import as a whole', function () {
    $this->postJson("/api/v1/stocks/{$this->stock->id}/prices/import", [
        'file' => priceCsv("date,price\n2026-09-06,550\n"),
        'timeframe' => '1D',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'Missing required column(s)']);

    expect(PriceBar::query()->count())->toBe(0);
});

it('validates the upload', function () {
    $this->postJson("/api/v1/stocks/{$this->stock->id}/prices/import", ['timeframe' => '2D'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file', 'timeframe']);

    $this->postJson("/api/v1/stocks/{$this->stock->id}/prices/import", [
        'file' => UploadedFile::fake()->createWithContent('chart.png', chartImageBytes()),
        'timeframe' => '1D',
    ])->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('returns stored bars in ascending order within a date range', function () {
    $this->postJson("/api/v1/stocks/{$this->stock->id}/prices/import", ['file' => priceCsv(THREE_DAYS), 'timeframe' => '1D'])->assertOk();

    $this->getJson("/api/v1/stocks/{$this->stock->id}/prices?timeframe=1D&from=2026-09-07")
        ->assertOk()
        ->assertJsonPath('meta.count', 2)
        ->assertJsonPath('data.0.time', '2026-09-07T00:00:00Z')
        ->assertJsonPath('data.0.close', 566)
        ->assertJsonPath('data.1.time', '2026-09-08T00:00:00Z');
});

it('returns the most recent bars when no range is given', function () {
    $this->postJson("/api/v1/stocks/{$this->stock->id}/prices/import", ['file' => priceCsv(THREE_DAYS), 'timeframe' => '1D'])->assertOk();

    $this->getJson("/api/v1/stocks/{$this->stock->id}/prices?limit=1")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.time', '2026-09-08T00:00:00Z');
});

it('404s for unknown stocks', function () {
    $this->getJson('/api/v1/stocks/999999/prices')->assertNotFound();
});
