<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Prediction\Models\Prediction;
use Illuminate\Auth\Access\Response;

final class PredictionPolicy
{
    public function view(User $user, Prediction $prediction): Response
    {
        return $this->owner($user, $prediction);
    }

    public function update(User $user, Prediction $prediction): Response
    {
        return $this->owner($user, $prediction);
    }

    private function owner(User $user, Prediction $prediction): Response
    {
        return $prediction->user()->is($user) ? Response::allow() : Response::denyAsNotFound();
    }
}
