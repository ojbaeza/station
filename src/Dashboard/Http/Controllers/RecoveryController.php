<?php

declare(strict_types=1);

namespace Station\Dashboard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Station\Contracts\JobResumerInterface;
use Station\Contracts\StuckJobDetectorInterface;
use Station\Dashboard\Http\Controllers\Traits\ApiHelpers;
use Throwable;

final class RecoveryController extends Controller
{
    use ApiHelpers;

    public function __construct(
        private readonly StuckJobDetectorInterface $stuckJobDetector,
        private readonly JobResumerInterface $jobResumer,
    ) {}

    /**
     * List stuck jobs.
     */
    public function stuckJobs(Request $request): JsonResponse
    {
        try {
            $threshold = (int) $request->get('threshold', 0);
            $options = $threshold > 0 ? ['threshold' => $threshold] : [];

            $jobs = $this->stuckJobDetector->detect($options);

            return response()->json([
                'data' => $jobs->values()->all(),
                'total' => $jobs->count(),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to detect stuck jobs');
        }
    }

    /**
     * Recover a single stuck job.
     */
    public function recoverJob(string $id, Request $request): JsonResponse
    {
        $strategy = $request->input('strategy', 'graceful');

        try {
            $result = $this->jobResumer->resume($id, $strategy);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Job recovery initiated' : 'Unable to recover job',
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to recover job');
        }
    }

    /**
     * Bulk recover stuck jobs.
     */
    public function bulkRecoverJobs(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|max:100',
            'ids.*' => 'string',
            'strategy' => 'string|in:graceful,restart,checkpoint',
        ]);

        $strategy = $request->input('strategy', 'graceful');

        return $this->bulkAction(
            $request->input('ids'),
            fn(string $id) => $this->jobResumer->resume($id, $strategy),
        );
    }

    /**
     * Recover stuck jobs.
     */
    public function recoverStuck(Request $request): JsonResponse
    {
        $strategy = $request->input('strategy', 'graceful');

        try {
            $recovered = $this->jobResumer->recoverAll($strategy);

            return response()->json([
                'message' => "Recovered {$recovered} stuck jobs",
                'count' => $recovered,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to recover stuck jobs');
        }
    }
}
