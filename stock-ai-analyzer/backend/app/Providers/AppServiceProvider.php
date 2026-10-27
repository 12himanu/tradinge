<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Policies\AnalysisPolicy;
use App\Application\Policies\ChartUploadPolicy;
use App\Application\Policies\PredictionPolicy;
use App\Application\Policies\TradeJournalEntryPolicy;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Chart\Models\ChartUpload;
use App\Domain\Journal\Models\TradeJournalEntry;
use App\Domain\Prediction\Models\Prediction;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Date::use(CarbonImmutable::class);

        $this->configureRateLimiting();

        // Policies live outside App\Policies, so they are registered explicitly.
        Gate::policy(ChartUpload::class, ChartUploadPolicy::class);
        Gate::policy(Analysis::class, AnalysisPolicy::class);
        Gate::policy(Prediction::class, PredictionPolicy::class);
        Gate::policy(TradeJournalEntry::class, TradeJournalEntryPolicy::class);
    }

    private function configureRateLimiting(): void
    {
        $byUser = static fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($byUser($request)));
        RateLimiter::for('upload', fn (Request $request) => Limit::perMinute(20)->by($byUser($request)));
        RateLimiter::for('analyze', fn (Request $request) => Limit::perMinute(10)->by($byUser($request)));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            Str::lower($request->string('email')->toString()).'|'.$request->ip()
        ));
    }
}
