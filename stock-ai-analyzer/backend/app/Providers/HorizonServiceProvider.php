<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Horizon is open in the local environment; elsewhere only the owner account may view it.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null): bool {
            $owner = config('stock_ai.owner.email');

            return $user !== null && is_string($owner) && $owner !== '' && $user->email === strtolower($owner);
        });
    }
}
