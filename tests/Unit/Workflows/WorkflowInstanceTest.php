<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Workflows;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Station\Enums\WorkflowStatus;
use Station\Workflows\WorkflowInstance;

class WorkflowInstanceTest extends TestCase
{
    public function testConstructorSetsDefaults(): void
    {
        $instance = new WorkflowInstance('def-id', 'test-workflow', ['key' => 'value']);

        $this->assertSame('def-id', $instance->getDefinitionId());
        $this->assertSame('test-workflow', $instance->getDefinitionName());
        $this->assertSame(['key' => 'value'], $instance->getInput());
        $this->assertSame(WorkflowStatus::Pending->value, $instance->getStatus());
    }

    public function testGetIdReturnsUuid7(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $instance->getId(),
        );
    }

    public function testEachInstanceHasUniqueId(): void
    {
        $instance1 = new WorkflowInstance('def-id', 'test');
        $instance2 = new WorkflowInstance('def-id', 'test');

        $this->assertNotSame($instance1->getId(), $instance2->getId());
    }

    public function testGetCurrentStepReturnsNullByDefault(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $this->assertNull($instance->getCurrentStep());
    }

    public function testContextIsInitializedFromInput(): void
    {
        $input = ['user_id' => 123, 'action' => 'process'];
        $instance = new WorkflowInstance('def-id', 'test', $input);

        $this->assertSame($input, $instance->getContext());
    }

    public function testGetContextValueReturnsValue(): void
    {
        $instance = new WorkflowInstance('def-id', 'test', ['key' => 'value']);

        $this->assertSame('value', $instance->getContextValue('key'));
    }

    public function testGetContextValueReturnsDefaultForMissingKey(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $this->assertNull($instance->getContextValue('missing'));
        $this->assertSame('default', $instance->getContextValue('missing', 'default'));
    }

    public function testSetContextValueUpdatesContext(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->setContextValue('key', 'value');

        $this->assertSame('value', $instance->getContextValue('key'));
    }

    public function testMergeContextMergesData(): void
    {
        $instance = new WorkflowInstance('def-id', 'test', ['existing' => 'value']);

        $instance->mergeContext(['new_key' => 'new_value']);

        $this->assertSame('value', $instance->getContextValue('existing'));
        $this->assertSame('new_value', $instance->getContextValue('new_key'));
    }

    public function testGetResultsReturnsEmptyByDefault(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $this->assertEmpty($instance->getResults());
    }

    public function testSetStepResultAndGetStepResult(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->setStepResult('step1', ['data' => 'result']);

        $this->assertSame(['data' => 'result'], $instance->getStepResult('step1'));
    }

    public function testGetStepResultReturnsNullForMissingStep(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $this->assertNull($instance->getStepResult('nonexistent'));
    }

    public function testGetErrorReturnsNullByDefault(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $this->assertNull($instance->getError());
    }

    public function testGetStepStatusesReturnsEmptyByDefault(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $this->assertEmpty($instance->getStepStatuses());
    }

    public function testGetStepStatusReturnsNullForUnknownStep(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $this->assertNull($instance->getStepStatus('unknown'));
    }

    public function testIsStepCompletedReturnsFalseForUnknownStep(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $this->assertFalse($instance->isStepCompleted('unknown'));
    }

    public function testAreDependenciesCompletedReturnsTrueForEmptyDeps(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $this->assertTrue($instance->areDependenciesCompleted([]));
    }

    public function testAreDependenciesCompletedReturnsFalseForIncompleteDeps(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->startStep('step1');

        $this->assertFalse($instance->areDependenciesCompleted(['step1']));
    }

    public function testAreDependenciesCompletedReturnsTrueWhenAllComplete(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->completeStep('step1');
        $instance->completeStep('step2');

        $this->assertTrue($instance->areDependenciesCompleted(['step1', 'step2']));
    }

    public function testStartUpdatesStatusAndTimestamp(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $this->assertNull($instance->getStartedAt());

        $instance->start();

        $this->assertSame(WorkflowStatus::Running->value, $instance->getStatus());
        $this->assertNotNull($instance->getStartedAt());
    }

    public function testStartStepUpdatesCurrentStepAndStatus(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->startStep('step1');

        $this->assertSame('step1', $instance->getCurrentStep());
        $this->assertSame('running', $instance->getStepStatus('step1'));
    }

    public function testCompleteStepUpdatesStatusAndResult(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->startStep('step1');
        $instance->completeStep('step1', 'result-data');

        $this->assertSame('completed', $instance->getStepStatus('step1'));
        $this->assertSame('result-data', $instance->getStepResult('step1'));
        $this->assertNull($instance->getCurrentStep());
    }

    public function testCompleteStepWithoutResultDoesNotStoreNull(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->completeStep('step1');

        $this->assertSame('completed', $instance->getStepStatus('step1'));
        $this->assertNull($instance->getStepResult('step1'));
    }

    public function testCompleteStepClearsCurrentStepOnlyIfMatches(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->startStep('step1');
        $instance->completeStep('step2'); // Different step

        // Current step should still be step1 because step2 is not the current step
        $this->assertSame('step1', $instance->getCurrentStep());
    }

    public function testFailStepUpdatesStatusAndError(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->failStep('step1', 'Something went wrong');

        $this->assertSame('failed', $instance->getStepStatus('step1'));
        $this->assertSame("Step 'step1' failed: Something went wrong", $instance->getError());
    }

    public function testSkipStepUpdatesStatus(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->skipStep('step1');

        $this->assertSame('skipped', $instance->getStepStatus('step1'));
    }

    public function testCompleteUpdatesStatusAndTimestamp(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->start();
        $instance->startStep('step1');
        $instance->complete();

        $this->assertSame(WorkflowStatus::Completed->value, $instance->getStatus());
        $this->assertNotNull($instance->getCompletedAt());
        $this->assertNull($instance->getCurrentStep());
    }

    public function testFailUpdatesStatusErrorAndTimestamp(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->start();
        $instance->fail('Workflow failed');

        $this->assertSame(WorkflowStatus::Failed->value, $instance->getStatus());
        $this->assertSame('Workflow failed', $instance->getError());
        $this->assertNotNull($instance->getCompletedAt());
    }

    public function testCancelUpdatesStatusAndTimestamp(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->start();
        $instance->cancel();

        $this->assertSame(WorkflowStatus::Cancelled->value, $instance->getStatus());
        $this->assertNotNull($instance->getCompletedAt());
    }

    public function testPauseUpdatesStatus(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->start();
        $instance->pause();

        $this->assertSame(WorkflowStatus::Paused->value, $instance->getStatus());
    }

    public function testResumeUpdatesStatus(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->start();
        $instance->pause();
        $instance->resume();

        $this->assertSame(WorkflowStatus::Running->value, $instance->getStatus());
    }

    public function testIsFinishedReturnsTrueForCompleted(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->complete();

        $this->assertTrue($instance->isFinished());
    }

    public function testIsFinishedReturnsTrueForFailed(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->fail('Error');

        $this->assertTrue($instance->isFinished());
    }

    public function testIsFinishedReturnsTrueForCancelled(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->cancel();

        $this->assertTrue($instance->isFinished());
    }

    public function testIsFinishedReturnsFalseForRunning(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->start();

        $this->assertFalse($instance->isFinished());
    }

    public function testIsFinishedReturnsFalseForPaused(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->pause();

        $this->assertFalse($instance->isFinished());
    }

    public function testGetProgressReturnsZeroForNoSteps(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $this->assertSame(0, $instance->getProgress());
    }

    public function testGetProgressCalculatesPercentage(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->completeStep('step1');
        $instance->startStep('step2'); // running, not counted
        $instance->skipStep('step3');
        $instance->startStep('step4');

        // 2 completed/skipped out of 4 = 50%
        $this->assertSame(50, $instance->getProgress());
    }

    public function testGetProgressReturns100WhenAllComplete(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->completeStep('step1');
        $instance->completeStep('step2');
        $instance->skipStep('step3');

        $this->assertSame(100, $instance->getProgress());
    }

    public function testGetCreatedAtReturnsTimestamp(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $this->assertInstanceOf(DateTimeImmutable::class, $instance->getCreatedAt());
    }

    public function testGetStartedAtReturnsNullBeforeStart(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $this->assertNull($instance->getStartedAt());
    }

    public function testGetCompletedAtReturnsNullBeforeComplete(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $this->assertNull($instance->getCompletedAt());
    }

    public function testGetDurationReturnsNullBeforeStart(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $this->assertNull($instance->getDuration());
    }

    public function testGetDurationReturnsSecondsAfterStart(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->start();

        $duration = $instance->getDuration();

        $this->assertIsInt($duration);
        $this->assertGreaterThanOrEqual(0, $duration);
    }

    public function testGetDurationReturnsFixedAfterComplete(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->start();
        $instance->complete();

        $duration = $instance->getDuration();

        $this->assertIsInt($duration);
        $this->assertGreaterThanOrEqual(0, $duration);
    }

    public function testToArrayReturnsAllData(): void
    {
        $instance = new WorkflowInstance('def-id', 'test-workflow', ['key' => 'value']);

        $instance->start();
        $instance->startStep('step1');
        $instance->completeStep('step1', 'result');
        $instance->complete();

        $array = $instance->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertSame('def-id', $array['definition_id']);
        $this->assertSame('test-workflow', $array['definition_name']);
        $this->assertSame(WorkflowStatus::Completed->value, $array['status']);
        $this->assertNull($array['current_step']);
        $this->assertSame(['key' => 'value'], $array['input']);
        $this->assertArrayHasKey('context', $array);
        $this->assertSame(['step1' => 'result'], $array['results']);
        $this->assertSame(['step1' => 'completed'], $array['step_statuses']);
        $this->assertNull($array['error']);
        $this->assertSame(100, $array['progress']);
        $this->assertArrayHasKey('created_at', $array);
        $this->assertArrayHasKey('started_at', $array);
        $this->assertArrayHasKey('completed_at', $array);
        $this->assertArrayHasKey('duration', $array);
    }

    public function testFromArrayRestoresInstance(): void
    {
        $data = [
            'id' => 'test-id-123',
            'definition_id' => 'def-id',
            'definition_name' => 'test-workflow',
            'status' => WorkflowStatus::Running->value,
            'current_step' => 'step2',
            'input' => ['key' => 'value'],
            'context' => ['key' => 'value', 'extra' => 'data'],
            'results' => ['step1' => 'result1'],
            'step_statuses' => ['step1' => 'completed', 'step2' => 'running'],
            'error' => null,
            'created_at' => '2025-01-01 10:00:00',
            'started_at' => '2025-01-01 10:01:00',
            'completed_at' => null,
        ];

        $instance = WorkflowInstance::fromArray($data);

        $this->assertSame('test-id-123', $instance->getId());
        $this->assertSame('def-id', $instance->getDefinitionId());
        $this->assertSame('test-workflow', $instance->getDefinitionName());
        $this->assertSame(WorkflowStatus::Running->value, $instance->getStatus());
        $this->assertSame('step2', $instance->getCurrentStep());
        $this->assertSame(['key' => 'value'], $instance->getInput());
        $this->assertSame(['key' => 'value', 'extra' => 'data'], $instance->getContext());
        $this->assertSame(['step1' => 'result1'], $instance->getResults());
        $this->assertSame(['step1' => 'completed', 'step2' => 'running'], $instance->getStepStatuses());
        $this->assertNull($instance->getError());
        $this->assertSame('2025-01-01 10:00:00', $instance->getCreatedAt()->format('Y-m-d H:i:s'));
        $this->assertSame('2025-01-01 10:01:00', $instance->getStartedAt()->format('Y-m-d H:i:s'));
        $this->assertNull($instance->getCompletedAt());
    }

    public function testFromArrayRestoresCompletedInstance(): void
    {
        $data = [
            'id' => 'completed-id',
            'definition_id' => 'def-id',
            'definition_name' => 'test',
            'status' => WorkflowStatus::Failed->value,
            'current_step' => null,
            'input' => [],
            'context' => [],
            'results' => [],
            'step_statuses' => ['step1' => 'failed'],
            'error' => 'Something went wrong',
            'created_at' => '2025-01-01 10:00:00',
            'started_at' => '2025-01-01 10:01:00',
            'completed_at' => '2025-01-01 10:05:00',
        ];

        $instance = WorkflowInstance::fromArray($data);

        $this->assertSame('completed-id', $instance->getId());
        $this->assertSame(WorkflowStatus::Failed->value, $instance->getStatus());
        $this->assertSame('Something went wrong', $instance->getError());
        $this->assertSame('2025-01-01 10:05:00', $instance->getCompletedAt()->format('Y-m-d H:i:s'));
    }

    public function testFromArrayWithMinimalData(): void
    {
        $data = [
            'id' => 'minimal-id',
            'definition_id' => 'def-id',
            'definition_name' => 'test',
            'status' => WorkflowStatus::Pending->value,
        ];

        $instance = WorkflowInstance::fromArray($data);

        $this->assertSame('minimal-id', $instance->getId());
        $this->assertSame('def-id', $instance->getDefinitionId());
        $this->assertSame('test', $instance->getDefinitionName());
        $this->assertSame(WorkflowStatus::Pending->value, $instance->getStatus());
        $this->assertNull($instance->getCurrentStep());
        $this->assertEmpty($instance->getInput());
        $this->assertEmpty($instance->getContext());
        $this->assertEmpty($instance->getResults());
        $this->assertEmpty($instance->getStepStatuses());
        $this->assertNull($instance->getError());
    }

    public function testStatusConstants(): void
    {
        $this->assertSame('pending', WorkflowStatus::Pending->value);
        $this->assertSame('running', WorkflowStatus::Running->value);
        $this->assertSame('paused', WorkflowStatus::Paused->value);
        $this->assertSame('completed', WorkflowStatus::Completed->value);
        $this->assertSame('failed', WorkflowStatus::Failed->value);
        $this->assertSame('cancelled', WorkflowStatus::Cancelled->value);
    }

    public function testIsStepCompletedReturnsTrueForCompleted(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->completeStep('step1');

        $this->assertTrue($instance->isStepCompleted('step1'));
    }

    public function testIsStepCompletedReturnsFalseForRunning(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->startStep('step1');

        $this->assertFalse($instance->isStepCompleted('step1'));
    }

    public function testIsStepCompletedReturnsFalseForSkipped(): void
    {
        $instance = new WorkflowInstance('def-id', 'test');

        $instance->skipStep('step1');

        $this->assertFalse($instance->isStepCompleted('step1'));
    }

    public function testFullWorkflowLifecycle(): void
    {
        $instance = new WorkflowInstance('def-id', 'full-workflow', ['user' => 'john']);

        // Start workflow
        $instance->start();
        $this->assertSame(WorkflowStatus::Running->value, $instance->getStatus());

        // Run step1
        $instance->startStep('step1');
        $this->assertSame('step1', $instance->getCurrentStep());
        $this->assertSame('running', $instance->getStepStatus('step1'));

        // Complete step1
        $instance->completeStep('step1', 'result1');
        $this->assertTrue($instance->isStepCompleted('step1'));

        // Run step2 (depends on step1)
        $this->assertTrue($instance->areDependenciesCompleted(['step1']));
        $instance->startStep('step2');

        // Skip step3 (condition not met)
        $instance->skipStep('step3');

        // Complete step2
        $instance->completeStep('step2', 'result2');

        // Complete workflow
        $instance->complete();

        $this->assertTrue($instance->isFinished());
        $this->assertSame(WorkflowStatus::Completed->value, $instance->getStatus());
        $this->assertSame(100, $instance->getProgress());
    }
}
