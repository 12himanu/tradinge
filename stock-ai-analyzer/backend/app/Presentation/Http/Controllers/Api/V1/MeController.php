<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Presentation\Http\Requests\UpdateSettingsRequest;
use App\Presentation\Http\Resources\UserResource;
use Illuminate\Http\Request;

final class MeController
{
    public function __invoke(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user->load('defaultExchange'));
    }

    /**
     * Update account settings (P5-20). Only the keys sent are changed, so a partial form cannot
     * silently reset the risk settings the engine sizes positions with.
     */
    public function updateSettings(UpdateSettingsRequest $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        $current = is_array($user->settings) ? $user->settings : [];
        $user->forceFill(['settings' => [...$current, ...$request->settings()]])->save();

        return new UserResource($user->load('defaultExchange'));
    }
}
