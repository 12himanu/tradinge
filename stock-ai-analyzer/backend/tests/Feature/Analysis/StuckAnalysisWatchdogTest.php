<?php

declare(strict_types=1);

use App\Application\Jobs\StuckAnalysisWatchdog;
use App\Application\Services\AnalysisProgress;
use App\Domain\Analysis\Enums\AnalysisStatus;
use App\Domain\Analysis\Models\Analysis;

/**
 * Nothing may sit in `running` forever (P4D-08): the UI polls it until the user gives up.
 */
function runWatchdog(): void
{
    app()->call([new StuckAnalysisWatchdog, 'handle']);
}

it('fails a run that stopped responding', function () {
    $analysis = Analysis::factory()->create([
        'status' => AnalysisStatus::Running,
        'started_at' => now()->subMinutes(StuckAnalysisWatchdog::RUNNING_GRACE_MINUTES + 5),
    ]);

    runWatchdog();

    expect($analysis->refresh()->status)->toBe(AnalysisStatus::Failed)
        ->and($analysis->error_code)->toBe('ANALYSIS_STALLED')
        ->and($analysis->error_message)->toContain('Try running it again')
        ->and($analysis->completed_at)->not->toBeNull();
});

it('fails a job no worker ever picked up', function () {
    $analysis = Analysis::factory()->create([
        'status' => AnalysisStatus::Queued,
        'queued_at' => now()->subMinutes(StuckAnalysisWatchdog::QUEUED_GRACE_MINUTES + 5),
        'started_at' => null,
    ]);

    runWatchdog();

    expect($analysis->refresh()->status)->toBe(AnalysisStatus::Failed)
        ->and($analysis->error_code)->toBe('ANALYSIS_NEVER_STARTED')
        ->and($analysis->error_message)->toContain('Horizon');
});

it('leaves a run that is still inside its grace period alone', function () {
    $running = Analysis::factory()->create([
        'status' => AnalysisStatus::Running,
        'started_at' => now()->subMinutes(2),
    ]);
    $queued = Analysis::factory()->create([
        'status' => AnalysisStatus::Queued,
        'queued_at' => now()->subMinutes(2),
    ]);

    runWatchdog();

    expect($running->refresh()->status)->toBe(AnalysisStatus::Running)
        ->and($queued->refresh()->status)->toBe(AnalysisStatus::Queued);
});

it('never touches a terminal analysis', function () {
    $completed = Analysis::factory()->create([
        'status' => AnalysisStatus::Completed,
        'started_at' => now()->subDay(),
        'completed_at' => now()->subDay(),
    ]);

    runWatchdog();

    expect($completed->refresh()->status)->toBe(AnalysisStatus::Completed)
        ->and($completed->error_code)->toBeNull();
});

it('clears the stale progress entry', function () {
    $analysis = Analysis::factory()->create([
        'status' => AnalysisStatus::Running,
        'started_at' => now()->subHour(),
    ]);
    app(AnalysisProgress::class)->publish($analysis->id, 'patterns', 0.7);

    runWatchdog();

    expect(app(AnalysisProgress::class)->read($analysis->id))->toBeNull();
});
