<?php

declare(strict_types=1);

namespace Station\Dashboard\Http\Controllers\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

trait ApiHelpers
{
    /**
     * Execute a bulk action with continue-on-error strategy.
     *
     * @param list<string> $ids
     * @param callable(string): mixed $action
     */
    private function bulkAction(array $ids, callable $action): JsonResponse
    {
        $processed = 0;
        $failed = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $action($id);
                $processed++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = [
                    'id' => $id,
                    'message' => app()->isProduction() ? 'Operation failed' : $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => $failed === 0,
            'processed' => $processed,
            'failed' => $failed,
            'errors' => $errors,
        ]);
    }

    /**
     * Clamp per_page parameter to a safe range.
     */
    private function clampPerPage(Request $request, int $default = 25, int $max = 100): int
    {
        $perPage = (int) $request->get('per_page', $default);

        return max(1, min($perPage, $max));
    }

    /**
     * Create a safe error response that doesn't leak internal details in production.
     */
    private function errorResponse(Throwable $e, string $fallback, int $status = 400): JsonResponse
    {
        Log::error($fallback, [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        $message = app()->isProduction() ? $fallback : $e->getMessage();

        return response()->json(['error' => $message], $status);
    }
}
