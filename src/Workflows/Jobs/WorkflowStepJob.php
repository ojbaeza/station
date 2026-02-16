<?php

declare(strict_types=1);

namespace Station\Workflows\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Station\Workflows\WorkflowManager;
use Throwable;

final class WorkflowStepJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public ?string $stationWorkflowInstanceId = null;

    public ?string $stationWorkflowStepName = null;

    public ?int $tries = null;

    public ?int $timeout = null;

    /** @var int|array<int, int>|null */
    public int|array|null $backoff = null;

    public function __construct(
        public readonly string $instanceId,
        public readonly string $stepName,
        public readonly string $definitionName,
    ) {
        $this->stationWorkflowInstanceId = $instanceId;
        $this->stationWorkflowStepName = $stepName;
    }

    public function handle(WorkflowManager $manager): void
    {
        $manager->executeAsyncStep($this->instanceId, $this->stepName, $this->definitionName);
    }

    public function failed(Throwable $exception): void
    {
        app(WorkflowManager::class)->handleAsyncStepFailure(
            $this->instanceId,
            $this->stepName,
            $exception->getMessage(),
        );
    }
}
