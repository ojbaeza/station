<?php

declare(strict_types=1);

namespace Station\Dashboard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Station\Contracts\BatchRepositoryInterface;
use Station\Contracts\JobRepositoryInterface;
use Station\Core\BatchManager;
use Station\Dashboard\Http\Controllers\Traits\ApiHelpers;
use Throwable;

final class BatchController extends Controller
{
    use ApiHelpers;

    public function __construct(
        private readonly BatchRepositoryInterface $batchRepository,
        private readonly BatchManager $batchManager,
        private readonly JobRepositoryInterface $jobRepository,
    ) {}

    /**
     * Get batches.
     */
    public function batches(Request $request): JsonResponse
    {
        $status = $request->get('status');
        $page = (int) $request->get('page', 1);
        $perPage = $this->clampPerPage($request);

        $batches = $this->batchRepository->paginate([
            'status' => $status,
        ], $page, $perPage);

        return response()->json($batches);
    }

    /**
     * Get a single batch.
     */
    public function batch(string $id): JsonResponse
    {
        $batch = $this->batchRepository->find($id);

        if ($batch === null) {
            return response()->json(['error' => 'Batch not found'], 404);
        }

        return response()->json([
            'batch' => $batch,
            'jobs' => $this->jobRepository->getByBatch($id),
        ]);
    }

    /**
     * Cancel a batch.
     */
    public function cancelBatch(string $id): JsonResponse
    {
        try {
            $result = $this->batchManager->cancel($id);

            if (!$result) {
                return response()->json(['error' => 'Batch not found or already finished'], 400);
            }

            return response()->json(['message' => 'Batch cancelled']);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to cancel batch');
        }
    }

    /**
     * Retry a batch.
     */
    public function retryBatch(string $id): JsonResponse
    {
        try {
            $count = $this->batchManager->retryFailed($id);

            return response()->json([
                'message' => "Queued {$count} jobs for retry",
                'count' => $count,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to retry batch');
        }
    }

    /**
     * Bulk cancel batches.
     */
    public function bulkCancelBatches(Request $request): JsonResponse
    {
        $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'string']);

        return $this->bulkAction($request->input('ids'), fn(string $id) => $this->batchManager->cancel($id));
    }

    /**
     * Bulk retry batches.
     */
    public function bulkRetryBatches(Request $request): JsonResponse
    {
        $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'string']);

        return $this->bulkAction($request->input('ids'), fn(string $id) => $this->batchManager->retryFailed($id));
    }

    /**
     * Bulk delete batches.
     */
    public function bulkDeleteBatches(Request $request): JsonResponse
    {
        $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'string']);

        return $this->bulkAction($request->input('ids'), fn(string $id) => $this->batchRepository->delete($id));
    }
}
