<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Api\V1;

use App\Application\Services\SystemHealthCheck;
use Illuminate\Http\JsonResponse;

final class HealthController
{
    public function __invoke(SystemHealthCheck $health): JsonResponse
    {
        return new JsonResponse(['data' => $health->run()]);
    }
}
