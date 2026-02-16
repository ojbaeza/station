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

final class RunWorkflowJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public ?string $stationWorkflowInstanceId = null;

    public ?string $stationWorkflowStepName = null;

    public function __construct(
        public readonly string $definitionName,
        public readonly string $instanceId,
    ) {
        $this->stationWorkflowInstanceId = $instanceId;
    }

    public function handle(WorkflowManager $manager): void
    {
        $manager->executeExistingInstance($this->definitionName, $this->instanceId);
    }

    public function failed(Throwable $exception): void
    {
        app(WorkflowManager::class)->handleAsyncStepFailure(
            $this->instanceId,
            '_starter',
            $exception->getMessage(),
        );
    }
}
