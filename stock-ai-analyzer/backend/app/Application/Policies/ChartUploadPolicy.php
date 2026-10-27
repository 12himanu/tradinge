<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Domain\Chart\Models\ChartUpload;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Users only ever see their own charts. Other users' ids answer 404, not 403, so ids can't be probed
 * (docs/05-api-spec.md §1).
 */
final class ChartUploadPolicy
{
    public function view(User $user, ChartUpload $upload): Response
    {
        return $this->owner($user, $upload);
    }

    public function delete(User $user, ChartUpload $upload): Response
    {
        return $this->owner($user, $upload);
    }

    private function owner(User $user, ChartUpload $upload): Response
    {
        return $upload->user()->is($user) ? Response::allow() : Response::denyAsNotFound();
    }
}
