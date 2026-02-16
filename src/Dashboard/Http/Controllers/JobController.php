<?php

declare(strict_types=1);

namespace Station\Dashboard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Station\Contracts\JobRepositoryInterface;
use Station\Core\JobManager;
use Station\Dashboard\Http\Controllers\Traits\ApiHelpers;
use Throwable;

final class JobController extends Controller
{
    use ApiHelpers;

    public function __construct(
        private readonly JobRepositoryInterface $jobRepository,
        private readonly JobManager $jobManager,
    ) {}

    /**
     * Get jobs list.
     */
    public function jobs(Request $request): JsonResponse
    {
        $queue = $request->get('queue');
        $status = $request->get('status');
        $page = (int) $request->get('page', 1);
        $perPage = $this->clampPerPage($request);

        $tag = $request->get('tag');
        $connection = $request->get('connection');
        $search = $request->get('search');

        $jobs = $this->jobRepository->paginate(array_filter([
            'queue' => $queue,
            'status' => $status,
            'tag' => $tag,
            'connection' => $connection,
            'search' => $search,
        ]), $page, $perPage);

        return response()->json($jobs);
    }

    /**
     * Get a single job.
     */
    public function job(string $id): JsonResponse
    {
        $job = $this->jobRepository->find($id);

        if ($job === null) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        return response()->json([
            'job' => $job,
            'events' => $this->jobRepository->getEvents($id),
        ]);
    }

    /**
     * Retry a job.
     */
    public function retryJob(string $id): JsonResponse
    {
        try {
            $result = $this->jobManager->retry($id);

            if (!$result) {
                $job = $this->jobRepository->find($id);
                if ($job === null) {
                    return response()->json(['error' => 'Job not found'], 404);
                }

                return response()->json([
                    'error' => "Job cannot be retried (status: {$job->status})",
                ], 400);
            }

            return response()->json(['message' => 'Job queued for retry']);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to retry job');
        }
    }

    /**
     * Cancel a job.
     */
    public function cancelJob(string $id): JsonResponse
    {
        try {
            $this->jobManager->cancel($id);

            return response()->json(['message' => 'Job cancelled']);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to cancel job');
        }
    }

    /**
     * Get failed jobs.
     */
    public function failedJobs(Request $request): JsonResponse
    {
        $queue = $request->get('queue');
        $page = (int) $request->get('page', 1);
        $perPage = $this->clampPerPage($request);

        $tag = $request->get('tag');
        $connection = $request->get('connection');

        $jobs = $this->jobRepository->paginateFailed(array_filter([
            'queue' => $queue,
            'tag' => $tag,
            'connection' => $connection,
        ]), $page, $perPage);

        return response()->json($jobs);
    }

    /**
     * Retry a failed job.
     */
    public function retryFailedJob(string $id): JsonResponse
    {
        try {
            $result = $this->jobManager->retry($id);

            if (!$result) {
                $job = $this->jobRepository->find($id);
                if ($job === null) {
                    return response()->json(['error' => 'Job not found'], 404);
                }

                return response()->json([
                    'error' => "Job cannot be retried (status: {$job->status})",
                ], 400);
            }

            return response()->json(['message' => 'Failed job queued for retry']);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to retry job');
        }
    }

    /**
     * Delete a failed job.
     */
    public function deleteFailedJob(string $id): JsonResponse
    {
        try {
            $this->jobRepository->deleteFailed($id);

            return response()->json(['message' => 'Failed job deleted']);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to delete job');
        }
    }

    /**
     * Retry all failed jobs.
     */
    public function retryAllFailed(Request $request): JsonResponse
    {
        $queue = $request->get('queue');

        try {
            $count = $this->jobManager->retryAllFailed($queue);

            return response()->json([
                'message' => "Queued {$count} jobs for retry",
                'count' => $count,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to retry jobs');
        }
    }

    /**
     * Flush failed jobs.
     */
    public function flushFailed(Request $request): JsonResponse
    {
        $queue = $request->get('queue');

        try {
            $count = $this->jobRepository->flushFailed($queue);

            return response()->json([
                'message' => "Deleted {$count} failed jobs",
                'count' => $count,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to flush jobs');
        }
    }

    /**
     * Bulk cancel jobs.
     */
    public function bulkCancelJobs(Request $request): JsonResponse
    {
        $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'string']);

        return $this->bulkAction($request->input('ids'), fn(string $id) => $this->jobManager->cancel($id));
    }

    /**
     * Bulk retry jobs.
     */
    public function bulkRetryJobs(Request $request): JsonResponse
    {
        $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'string']);

        return $this->bulkAction($request->input('ids'), fn(string $id) => $this->jobManager->retry($id));
    }

    /**
     * Bulk delete jobs.
     */
    public function bulkDeleteJobs(Request $request): JsonResponse
    {
        $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'string']);

        return $this->bulkAction($request->input('ids'), fn(string $id) => $this->jobRepository->delete($id));
    }

    /**
     * Bulk retry failed jobs.
     */
    public function bulkRetryFailed(Request $request): JsonResponse
    {
        $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'string']);

        return $this->bulkAction($request->input('ids'), fn(string $id) => $this->jobManager->retry($id));
    }

    /**
     * Bulk delete failed jobs.
     */
    public function bulkDeleteFailed(Request $request): JsonResponse
    {
        $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'string']);

        return $this->bulkAction($request->input('ids'), fn(string $id) => $this->jobRepository->deleteFailed($id));
    }

    /**
     * Get distinct tags.
     */
    public function tags(): JsonResponse
    {
        return response()->json($this->jobRepository->getDistinctTags());
    }

    /**
     * Add a tag to a job.
     */
    public function addJobTag(string $id, Request $request): JsonResponse
    {
        $request->validate(['tag' => 'required|string|max:100']);

        $job = $this->jobRepository->find($id);

        if ($job === null) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        try {
            $this->jobRepository->addTag($id, $request->input('tag'));

            return response()->json(['message' => 'Tag added']);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to add tag');
        }
    }

    /**
     * Remove a tag from a job.
     */
    public function removeJobTag(string $id, string $tag): JsonResponse
    {
        $job = $this->jobRepository->find($id);

        if ($job === null) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        try {
            $this->jobRepository->removeTag($id, $tag);

            return response()->json(['message' => 'Tag removed']);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to remove tag');
        }
    }
}
