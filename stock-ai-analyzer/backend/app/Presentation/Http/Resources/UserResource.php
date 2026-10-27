<?php

declare(strict_types=1);

namespace App\Presentation\Http\Resources;

use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'timezone' => $this->timezone,
            'settings' => $this->settings,
            'default_exchange' => $this->whenLoaded(
                'defaultExchange',
                fn () => $this->defaultExchange === null ? null : [
                    'code' => $this->defaultExchange->code,
                    'name' => $this->defaultExchange->name,
                    'currency' => $this->defaultExchange->currency,
                    'timezone' => $this->defaultExchange->timezone,
                ],
            ),
        ];
    }
}
