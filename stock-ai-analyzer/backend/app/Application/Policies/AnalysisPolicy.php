<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Domain\Analysis\Models\Analysis;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Analyses belong to the user who requested them. Someone else's id answers 404, not 403, so ids
 * can't be probed (docs/05-api-spec.md §1).
 */
final class AnalysisPolicy
{
    public function view(User $user, Analysis $analysis): Response
    {
        return $this->owner($user, $analysis);
    }

    public function update(User $user, Analysis $analysis): Response
    {
        return $this->owner($user, $analysis);
    }

    public function delete(User $user, Analysis $analysis): Response
    {
        return $this->owner($user, $analysis);
    }

    private function owner(User $user, Analysis $analysis): Response
    {
        return $analysis->user()->is($user) ? Response::allow() : Response::denyAsNotFound();
    }
}
