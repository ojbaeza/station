<?php

declare(strict_types=1);

namespace Station\Dashboard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class Authorize
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('station.dashboard.enabled', true)) {
            abort(404);
        }

        if (app()->environment('local')) {
            return $next($request);
        }

        return $this->allowedToAccess($request)
            ? $next($request)
            : abort(403);
    }

    /**
     * Determine if the user is allowed to access the dashboard.
     */
    protected function allowedToAccess(Request $request): bool
    {
        $gate = config('station.dashboard.authorization');

        if ($gate === null) {
            return true;
        }

        return Gate::allows($gate, [$request->user()]);
    }
}
