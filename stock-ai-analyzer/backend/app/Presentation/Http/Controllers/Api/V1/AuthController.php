<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Presentation\Http\Requests\LoginRequest;
use App\Presentation\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Sanctum SPA session login. The frontend first calls GET /sanctum/csrf-cookie.
 */
final class AuthController
{
    public function login(LoginRequest $request): UserResource
    {
        if (! $request->hasSession()) {
            throw new HttpException(400, 'Login requires a first-party browser session.');
        }

        if (! Auth::guard('web')->attempt($request->credentials(), $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();

        return new UserResource($user->load('defaultExchange'));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
