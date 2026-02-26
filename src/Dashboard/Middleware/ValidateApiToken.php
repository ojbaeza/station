<?php

declare(strict_types=1);

namespace Station\Dashboard\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ValidateApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if API is enabled
        if (!config('station.api.enabled', true)) {
            return response()->json(['error' => 'API is disabled'], 404);
        }

        // Check if authentication is required
        $auth = config('station.api.auth', 'token');
        if ($auth === 'none') {
            return $next($request);
        }

        // Get the token from the request
        $token = $this->getTokenFromRequest($request);

        if ($token === null) {
            return response()->json([
                'error' => 'API token required',
                'message' => 'Please provide a valid API token via the Authorization: Bearer header.',
            ], 401);
        }

        // Validate the token
        if (!$this->validateToken($token)) {
            return response()->json([
                'error' => 'Invalid API token',
                'message' => 'The provided API token is invalid or expired.',
            ], 401);
        }

        return $next($request);
    }

    /**
     * Get the API token from the request.
     */
    private function getTokenFromRequest(Request $request): ?string
    {
        // Only accept Bearer token via Authorization header (query string exposes tokens in logs/referrers)
        $authHeader = $request->header('Authorization');

        if ($authHeader !== null && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        return null;
    }

    /**
     * Validate the API token.
     */
    private function validateToken(string $token): bool
    {
        $configuredToken = config('station.api.token');

        if ($configuredToken === null) {
            // No token configured, deny access for security
            return false;
        }

        return hash_equals($configuredToken, $token);
    }
}
