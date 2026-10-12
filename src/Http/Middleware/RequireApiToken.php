<?php

declare(strict_types=1);

namespace Odden\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects server-to-server endpoints (webhooks, sending APIs) with a shared token.
 *
 * Usage: RequireApiToken::class.':odden-marketing.api.token' (the config key holding the token).
 * The token is accepted as a Bearer token or an X-Odden-Token header. A ?token= query
 * parameter is accepted only on routes that opt in with a second parameter, for providers
 * that can only be given a URL: RequireApiToken::class.':odden-marketing.api.token,query'.
 * Query strings end up in access logs and proxies, so it is off by default. Endpoints stay
 * disabled (fail closed) until a token is configured.
 */
final class RequireApiToken
{
    public function handle(Request $request, Closure $next, string $configKey, string $allowQuery = ''): Response
    {
        $expected = config($configKey);

        if (! is_string($expected) || $expected === '') {
            abort(403, 'This endpoint is disabled until an API token is configured.');
        }

        $given = $request->bearerToken() ?? $request->header('X-Odden-Token');

        if ($given === null && $allowQuery === 'query') {
            $given = $request->query('token');
        }

        if (! is_string($given) || ! hash_equals($expected, $given)) {
            abort(401, 'Invalid or missing API token.');
        }

        return $next($request);
    }
}
