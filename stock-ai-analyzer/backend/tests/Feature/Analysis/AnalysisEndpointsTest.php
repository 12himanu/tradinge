<?php

declare(strict_types=1);

use App\Application\Jobs\AnalyzeChartJob;
use App\Application\Services\AnalysisProgress;
use App\Domain\Analysis\Enums\AnalysisStatus;
use App\Domain\Analysis\Enums\Decision;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Chart\Models\ChartUpload;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Timeframe;
use App\Domain\Shared\Enums\TradingStyle;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->upload = ChartUpload::factory()->for($this->user)->create();
    $this->actingAs($this->user);
});

// --------------------------------------------------------------------------- POST /charts/{id}/analyze

it('queues an analysis and answers 202', function () {
    $response = $this->postJson("/api/v1/charts/{$this->upload->id}/analyze", [
        'timeframe' => '1D',
        'trading_style' => 'swing',
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.chart_upload_id', $this->upload->id);

    Queue::assertPushed(AnalyzeChartJob::class);
});

it('answers 200 when it reuses an existing analysis', function () {
    Analysis::factory()->for($this->user)->create([
        'chart_upload_id' => $this->upload->id,
        'status' => AnalysisStatus::Completed,
        'timeframe' => Timeframe::D1,
        'trading_style' => TradingStyle::Swing,
    ]);

    $this->postJson("/api/v1/charts/{$this->upload->id}/analyze", ['timeframe' => '1D'])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    Queue::assertNothingPushed();
});

it('answers 409 while an analysis is already running', function () {
    $running = Analysis::factory()->for($this->user)->create([
        'chart_upload_id' => $this->upload->id,
        'status' => AnalysisStatus::Running,
    ]);

    $this->postJson("/api/v1/charts/{$this->upload->id}/analyze")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect($this->postJson("/api/v1/charts/{$this->upload->id}/analyze")->json('error.message'))
        ->toContain($running->id);
});

it('rejects a holding position without its size', function () {
    $this->postJson("/api/v1/charts/{$this->upload->id}/analyze", [
        'position_status' => 'holding',
        'position_entry_price' => 540,
    ])->assertStatus(422)->assertJsonValidationErrors(['position_quantity']);
});

it('rejects an unknown timeframe', function () {
    $this->postJson("/api/v1/charts/{$this->upload->id}/analyze", ['timeframe' => '3D'])
        ->assertStatus(422)->assertJsonValidationErrors(['timeframe']);
});

it('does not let one user analyse another user\'s chart', function () {
    $stranger = ChartUpload::factory()->for(User::factory())->create();

    $this->postJson("/api/v1/charts/{$stranger->id}/analyze")->assertNotFound();
});

// --------------------------------------------------------------------------- GET /analysis/{id}

it('returns the full report with its children', function () {
    $analysis = Analysis::factory()->for($this->user)->create([
        'chart_upload_id' => $this->upload->id,
        'status' => AnalysisStatus::Completed,
        'decision' => Decision::Wait,
        'setup_score' => 64,
        'report_markdown' => '# Report',
        'evidence' => ['items' => [['id' => 'E1', 'text' => 'Last close 540']], 'score' => null],
    ]);

    $this->getJson("/api/v1/analysis/{$analysis->id}")
        ->assertOk()
        ->assertJsonPath('data.decision', 'wait')
        ->assertJsonPath('data.setup_score', 64)
        ->assertJsonPath('data.report_markdown', '# Report')
        ->assertJsonPath('data.evidence.0.id', 'E1')
        ->assertJsonStructure(['data' => ['indicators', 'patterns', 'levels', 'predictions', 'warnings']]);
});

it('hides another user\'s analysis behind a 404', function () {
    $stranger = Analysis::factory()->for(User::factory())->create();

    $this->getJson("/api/v1/analysis/{$stranger->id}")->assertNotFound();
});

it('requires authentication', function () {
    $analysis = Analysis::factory()->for($this->user)->create();

    auth()->logout();

    $this->getJson("/api/v1/analysis/{$analysis->id}")->assertUnauthorized();
});

// --------------------------------------------------------------------------- GET /analysis/{id}/status

it('reports live progress while running', function () {
    $analysis = Analysis::factory()->for($this->user)->create(['status' => AnalysisStatus::Running]);
    app(AnalysisProgress::class)->publish($analysis->id, 'patterns', 0.7);

    $this->getJson("/api/v1/analysis/{$analysis->id}/status")
        ->assertOk()
        ->assertJsonPath('data.stage', 'patterns')
        ->assertJsonPath('data.progress', 0.7)
        ->assertJsonPath('data.terminal', false);
});

it('reports terminal status with the error code', function () {
    $analysis = Analysis::factory()->for($this->user)->create([
        'status' => AnalysisStatus::Failed,
        'error_code' => 'ENGINE_UNAVAILABLE',
        'error_message' => 'Cannot reach the analysis engine.',
    ]);

    $this->getJson("/api/v1/analysis/{$analysis->id}/status")
        ->assertOk()
        ->assertJsonPath('data.terminal', true)
        // JSON has one number type: 1.0 is encoded as 1.
        ->assertJsonPath('data.progress', 1)
        ->assertJsonPath('data.error_code', 'ENGINE_UNAVAILABLE');
});

// --------------------------------------------------------------------------- cancel and feedback

it('cancels a running analysis', function () {
    $analysis = Analysis::factory()->for($this->user)->create(['status' => AnalysisStatus::Running]);

    $this->postJson("/api/v1/analysis/{$analysis->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($analysis->refresh()->completed_at)->not->toBeNull();
});

it('leaves a finished analysis untouched when cancelled', function () {
    $analysis = Analysis::factory()->for($this->user)->create(['status' => AnalysisStatus::Completed]);

    $this->postJson("/api/v1/analysis/{$analysis->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');
});

it('records feedback once per user', function () {
    $analysis = Analysis::factory()->for($this->user)->create();

    $this->postJson("/api/v1/analysis/{$analysis->id}/feedback", [
        'rating' => -1,
        'issue_type' => 'wrong_levels',
        'comment' => 'Resistance was 20 rupees too high.',
    ])->assertCreated()->assertJsonPath('data.rating', -1);

    $this->postJson("/api/v1/analysis/{$analysis->id}/feedback", ['rating' => 1])->assertCreated();

    expect($analysis->feedback()->count())->toBe(1)
        ->and($analysis->feedback()->first()->rating)->toBe(1);
});

it('validates the feedback rating', function () {
    $analysis = Analysis::factory()->for($this->user)->create();

    $this->postJson("/api/v1/analysis/{$analysis->id}/feedback", ['rating' => 5])
        ->assertStatus(422)->assertJsonValidationErrors(['rating']);
});

// --------------------------------------------------------------------------- GET /history

it('lists only the signed-in user\'s analyses, newest first', function () {
    Analysis::factory()->for(User::factory())->create();
    $older = Analysis::factory()->for($this->user)->create(['created_at' => now()->subDay()]);
    $newer = Analysis::factory()->for($this->user)->create(['created_at' => now()]);

    $response = $this->getJson('/api/v1/history')->assertOk();

    expect($response->json('data.*.id'))->toBe([$newer->id, $older->id]);
});

it('omits the heavy report payload from the listing', function () {
    Analysis::factory()->for($this->user)->create(['report_markdown' => '# Long report']);

    $row = $this->getJson('/api/v1/history')->assertOk()->json('data.0');

    expect($row)->not->toHaveKey('report_markdown')
        ->and($row)->not->toHaveKey('evidence')
        ->and($row)->toHaveKey('decision');
});

it('filters history by decision', function () {
    Analysis::factory()->for($this->user)->create(['decision' => Decision::Buy]);
    Analysis::factory()->for($this->user)->create(['decision' => Decision::Avoid]);

    $response = $this->getJson('/api/v1/history?decision=buy')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.decision'))->toBe('buy');
});
