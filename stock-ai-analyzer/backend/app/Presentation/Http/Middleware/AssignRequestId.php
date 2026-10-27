<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id for logs across Laravel, queued jobs (Context propagates) and the AI engine.
 */
final class AssignRequestId
{
    private const string PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get('X-Request-Id');
        $requestId = is_string($incoming) && preg_match(self::PATTERN, $incoming) === 1
            ? $incoming
            : (string) Str::ulid();

        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
