<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Error envelope for the public API: {"error": {"code", "message", "details"}} (docs/05-api-spec.md §1).
 */
final class ApiError
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $headers
     */
    public static function response(
        string $code,
        string $message,
        int $status,
        array $details = [],
        array $headers = [],
    ): JsonResponse {
        return new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message, 'details' => (object) $details]],
            $status,
            $headers,
        );
    }

    public static function fromHttpException(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();

        $code = match ($status) {
            400 => 'BAD_REQUEST',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            413 => 'PAYLOAD_TOO_LARGE',
            415 => 'UNSUPPORTED_MEDIA_TYPE',
            419 => 'CSRF_TOKEN_MISMATCH',
            429 => 'TOO_MANY_REQUESTS',
            503 => 'SERVICE_UNAVAILABLE',
            default => $status >= 500 ? 'SERVER_ERROR' : 'HTTP_ERROR',
        };

        // 404 messages from route model binding name internal classes; never expose them.
        $message = $status !== 404 && $e->getMessage() !== ''
            ? $e->getMessage()
            : (Response::$statusTexts[$status] ?? 'Error');

        return self::response($code, $message, $status, [], $e->getHeaders());
    }
}
