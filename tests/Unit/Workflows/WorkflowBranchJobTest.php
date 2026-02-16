<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Workflows;

use PHPUnit\Framework\TestCase;
use Station\Workflows\WorkflowBranchJob;

class WorkflowBranchJobTest extends TestCase
{
    public function testConstructorSetsInstanceIdAndContext(): void
    {
        $instanceId = 'workflow-123';
        $context = ['key' => 'value', 'number' => 42];

        $job = new WorkflowBranchJob($instanceId, $context);

        $this->assertInstanceOf(WorkflowBranchJob::class, $job);
    }

    public function testHandleDoesNothing(): void
    {
        $job = new WorkflowBranchJob('instance-1', []);

        // Handle is a no-op, just verify it doesn't throw
        $job->handle();

        $this->assertInstanceOf(WorkflowBranchJob::class, $job);
    }

    public function testGetContextUpdatesReturnsEmptyArrayByDefault(): void
    {
        $job = new WorkflowBranchJob('instance-1', ['foo' => 'bar']);

        $updates = $job->getContextUpdates();

        $this->assertSame([], $updates);
    }

    public function testWorkflowInstanceIdPropertyIsNullByDefault(): void
    {
        $job = new WorkflowBranchJob('instance-1', []);

        $this->assertNull($job->workflowInstanceId);
    }

    public function testWorkflowStepNamePropertyIsNullByDefault(): void
    {
        $job = new WorkflowBranchJob('instance-1', []);

        $this->assertNull($job->workflowStepName);
    }

    public function testWorkflowInstanceIdCanBeSet(): void
    {
        $job = new WorkflowBranchJob('instance-1', []);
        $job->workflowInstanceId = 'wf-instance-123';

        $this->assertSame('wf-instance-123', $job->workflowInstanceId);
    }

    public function testWorkflowStepNameCanBeSet(): void
    {
        $job = new WorkflowBranchJob('instance-1', []);
        $job->workflowStepName = 'validate-order';

        $this->assertSame('validate-order', $job->workflowStepName);
    }
}
