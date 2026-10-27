<?php

declare(strict_types=1);

use App\Domain\Chart\Models\ChartUpload;
use App\Domain\Identity\Models\User;
use App\Domain\Market\Models\Exchange;
use App\Domain\Market\Models\Stock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('charts');

    $this->nepse = Exchange::factory()->create(['code' => 'NEPSE']);
    $this->user = User::factory()->create(['default_exchange_id' => $this->nepse->id]);
    Sanctum::actingAs($this->user);
});

it('stores a screenshot privately as a re-encoded PNG', function () {
    $stock = Stock::factory()->for($this->nepse)->create(['symbol' => 'HDL', 'name' => 'Himalayan Distillery Limited']);

    $response = $this->postJson('/api/v1/charts/upload', [
        'image' => chartImage(),
        'symbol' => ' hdl ',
        'timeframe' => '1D',
        'trading_style' => 'swing',
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'uploaded')
        ->assertJsonPath('data.declared', ['symbol' => 'HDL', 'exchange' => 'NEPSE', 'timeframe' => '1D', 'trading_style' => 'swing'])
        ->assertJsonPath('data.stock', ['id' => $stock->id, 'symbol' => 'HDL', 'name' => 'Himalayan Distillery Limited'])
        ->assertJsonPath('data.image.width', 1200)
        ->assertJsonPath('data.image.height', 700)
        ->assertJsonPath('data.deduplicated', false)
        ->assertJsonPath('data.analysis', null);

    $upload = ChartUpload::query()->sole();
    $stored = Storage::disk('charts')->get($upload->path);

    expect($upload->path)->toMatch("#^{$this->user->id}/\\d{4}/\\d{2}/{$upload->id}\\.png$#")
        ->and($response->json('data.image.url'))->toBe("/api/v1/charts/{$upload->id}/image")
        ->and(substr((string) $stored, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(hash('sha256', (string) $stored))->toBe($upload->sha256)
        ->and(strlen((string) $stored))->toBe($upload->size_bytes)
        ->and(Storage::disk('charts')->allFiles())->toBe([$upload->path]);
});

it('re-encodes JPEGs to PNG and strips EXIF and other metadata', function () {
    $jpeg = chartImageBytes(format: 'jpeg');
    $exif = "Exif\0\0MM\0\x2A\0\0\0\x08\0\0\0\0\0\0";
    $comment = 'SECRET-GPS-27.7172,85.3240';
    $bytes = substr($jpeg, 0, 2)
        ."\xFF\xE1".pack('n', strlen($exif) + 2).$exif
        ."\xFF\xFE".pack('n', strlen($comment) + 2).$comment
        .substr($jpeg, 2);

    $this->postJson('/api/v1/charts/upload', [
        'image' => UploadedFile::fake()->createWithContent('photo.jpg', $bytes),
        'trading_style' => 'swing',
    ])->assertCreated();

    $upload = ChartUpload::query()->sole();
    $stored = (string) Storage::disk('charts')->get($upload->path);

    expect($upload->mime_type)->toBe('image/png')
        ->and($upload->original_filename)->toBe('photo.jpg')
        ->and(substr($stored, 0, 4))->toBe("\x89PNG")
        ->and($stored)->not->toContain('Exif')
        ->and($stored)->not->toContain('SECRET-GPS');
});

it('drops data appended to the image', function () {
    $this->postJson('/api/v1/charts/upload', [
        'image' => UploadedFile::fake()->createWithContent('chart.png', chartImageBytes().'<?php system($_GET["c"]); ?>'),
        'trading_style' => 'swing',
    ])->assertCreated();

    expect((string) Storage::disk('charts')->get(ChartUpload::query()->sole()->path))->not->toContain('<?php');
});

it('accepts WebP screenshots', function () {
    $this->postJson('/api/v1/charts/upload', ['image' => chartImage(format: 'webp'), 'trading_style' => 'swing'])
        ->assertCreated();
})->skip(! function_exists('imagewebp'), 'GD was built without WebP support.');

it('returns the existing upload when the same image is uploaded again', function () {
    $first = $this->postJson('/api/v1/charts/upload', ['image' => chartImage(seed: 7), 'trading_style' => 'swing'])
        ->assertCreated();

    $this->postJson('/api/v1/charts/upload', ['image' => chartImage(seed: 7), 'trading_style' => 'positional'])
        ->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'))
        ->assertJsonPath('data.deduplicated', true);

    expect(ChartUpload::query()->count())->toBe(1)
        ->and(Storage::disk('charts')->allFiles())->toHaveCount(1);
});

/**
 * The dedupe used to discard the symbol typed the second time, so the one way a user naturally
 * corrects a mislabelled chart — upload it again with the right ticker — gave them back the row
 * with the wrong one, and another report about the wrong instrument.
 */
it('re-points a deduplicated upload at the symbol declared the second time', function () {
    Stock::factory()->for($this->nepse)->create(['symbol' => 'PCBL', 'name' => 'Prime Commercial Bank Limited']);
    $upper = Stock::factory()->for($this->nepse)->create([
        'symbol' => 'UPPER',
        'name' => 'Upper Tamakoshi Hydropower Limited',
    ]);

    $first = $this->postJson('/api/v1/charts/upload', [
        'image' => chartImage(seed: 11),
        'symbol' => 'PCBL',
        'trading_style' => 'swing',
    ])->assertCreated();

    $this->postJson('/api/v1/charts/upload', [
        'image' => chartImage(seed: 11),
        'symbol' => 'UPPER',
        'trading_style' => 'swing',
    ])
        ->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'))
        ->assertJsonPath('data.deduplicated', true)
        ->assertJsonPath('data.declared.symbol', 'UPPER')
        ->assertJsonPath('data.stock.symbol', 'UPPER');

    expect(ChartUpload::query()->sole()->stock_id)->toBe($upper->id);
});

it('keeps the symbol a deduplicated upload has when none is declared again', function () {
    $stock = Stock::factory()->for($this->nepse)->create(['symbol' => 'PCBL', 'name' => 'Prime Commercial Bank']);

    $this->postJson('/api/v1/charts/upload', [
        'image' => chartImage(seed: 12),
        'symbol' => 'PCBL',
        'trading_style' => 'swing',
    ])->assertCreated();

    $this->postJson('/api/v1/charts/upload', ['image' => chartImage(seed: 12), 'trading_style' => 'swing'])
        ->assertOk()
        ->assertJsonPath('data.stock.symbol', 'PCBL');

    expect(ChartUpload::query()->sole()->stock_id)->toBe($stock->id);
});

it('deduplicates per user, not across users', function () {
    $this->postJson('/api/v1/charts/upload', ['image' => chartImage(seed: 3), 'trading_style' => 'swing'])->assertCreated();

    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/charts/upload', ['image' => chartImage(seed: 3), 'trading_style' => 'swing'])
        ->assertCreated()
        ->assertJsonPath('data.deduplicated', false);

    expect(ChartUpload::query()->count())->toBe(2);
});

it('rejects files that are not images, whatever their extension', function () {
    $this->postJson('/api/v1/charts/upload', [
        'image' => UploadedFile::fake()->createWithContent('chart.png', 'definitely not a chart'),
        'trading_style' => 'swing',
    ])
        ->assertStatus(415)
        ->assertJsonPath('error.code', 'UNSUPPORTED_IMAGE_TYPE');

    expect(Storage::disk('charts')->allFiles())->toBeEmpty();
});

it('rejects image types other than PNG, JPEG and WebP', function () {
    $this->postJson('/api/v1/charts/upload', ['image' => chartImage(format: 'gif'), 'trading_style' => 'swing'])
        ->assertStatus(415)
        ->assertJsonPath('error.details.detected_type', 'image/gif');
});

it('rejects oversized files with 413', function () {
    config(['stock_ai.uploads.max_bytes' => 1024]);

    $this->postJson('/api/v1/charts/upload', ['image' => chartImage(), 'trading_style' => 'swing'])
        ->assertStatus(413)
        ->assertJsonPath('error.code', 'FILE_TOO_LARGE')
        ->assertJsonPath('error.details.max_bytes', 1024);
});

it('rejects screenshots too small to read', function () {
    $response = $this->postJson('/api/v1/charts/upload', ['image' => chartImage(300, 200), 'trading_style' => 'swing'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_DIMENSIONS');

    expect($response->json('error.message'))->toContain('300×200')->toContain('600×400');
});

it('rejects images above the maximum dimensions', function () {
    config(['stock_ai.uploads.max_width' => 1000]);

    $this->postJson('/api/v1/charts/upload', ['image' => chartImage(1200, 700), 'trading_style' => 'swing'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_DIMENSIONS');
});

it('rejects a corrupt image', function () {
    $this->postJson('/api/v1/charts/upload', [
        'image' => UploadedFile::fake()->createWithContent('chart.png', substr(chartImageBytes(), 0, 120)),
        'trading_style' => 'swing',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_IMAGE');
});

it('validates the declared context', function () {
    $this->postJson('/api/v1/charts/upload', [
        'image' => chartImage(),
        'timeframe' => '2D',
        'exchange' => 'NOPE',
        'symbol' => 'NEPSE:HDL',
        'position_status' => 'holding',
        'thesis' => str_repeat('x', 2001),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'trading_style', 'timeframe', 'exchange', 'symbol', 'position_entry_price', 'position_quantity', 'thesis',
        ]);
});

it('accepts a holding position with entry price and quantity', function () {
    $this->postJson('/api/v1/charts/upload', [
        'image' => chartImage(),
        'trading_style' => 'swing',
        'position_status' => 'holding',
        'position_entry_price' => '552.5',
        'position_quantity' => '100',
    ])->assertCreated();
});

it('keeps an unknown symbol without linking a stock', function () {
    $this->postJson('/api/v1/charts/upload', ['image' => chartImage(), 'symbol' => 'zzzz', 'trading_style' => 'swing'])
        ->assertCreated()
        ->assertJsonPath('data.stock', null)
        ->assertJsonPath('data.declared.symbol', 'ZZZZ');
});

it('shows an upload to its owner and 404s for anyone else', function () {
    $mine = ChartUpload::factory()->for($this->user)->create();
    $theirs = ChartUpload::factory()->create();

    $this->getJson("/api/v1/charts/{$mine->id}")->assertOk()->assertJsonPath('data.id', $mine->id);
    $this->getJson("/api/v1/charts/{$theirs->id}")->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    $this->getJson('/api/v1/charts/not-a-ulid')->assertNotFound();
});

it('streams the image privately to its owner', function () {
    $this->postJson('/api/v1/charts/upload', ['image' => chartImage(), 'trading_style' => 'swing'])->assertCreated();
    $upload = ChartUpload::query()->sole();

    $response = $this->get("/api/v1/charts/{$upload->id}/image")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->streamedContent())->toBe(Storage::disk('charts')->get($upload->path));
});

it('hides images from other users and from guests', function () {
    $theirs = ChartUpload::factory()->create();
    $this->getJson("/api/v1/charts/{$theirs->id}/image")->assertNotFound();

    $mine = ChartUpload::factory()->for($this->user)->create();
    $this->app['auth']->forgetGuards();

    $this->getJson("/api/v1/charts/{$mine->id}/image")->assertUnauthorized();
    $this->postJson('/api/v1/charts/upload', ['image' => chartImage(), 'trading_style' => 'swing'])->assertUnauthorized();
});

it('soft deletes an upload and allows the same image to be uploaded again', function () {
    $id = $this->postJson('/api/v1/charts/upload', ['image' => chartImage(seed: 9), 'trading_style' => 'swing'])
        ->assertCreated()
        ->json('data.id');

    $this->deleteJson("/api/v1/charts/{$id}")->assertNoContent();
    $this->getJson("/api/v1/charts/{$id}")->assertNotFound();

    expect(ChartUpload::withTrashed()->find($id)?->trashed())->toBeTrue();

    $this->postJson('/api/v1/charts/upload', ['image' => chartImage(seed: 9), 'trading_style' => 'swing'])
        ->assertCreated()
        ->assertJsonPath('data.deduplicated', false);
});

it("doesn't let users delete other users' uploads", function () {
    $theirs = ChartUpload::factory()->create();

    $this->deleteJson("/api/v1/charts/{$theirs->id}")->assertNotFound();

    expect($theirs->fresh()?->trashed())->toBeFalse();
});
