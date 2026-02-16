<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Workflows\Jobs;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Station\Workflows\Jobs\RunWorkflowJob;
use Station\Workflows\Jobs\WorkflowStepJob;
use Station\Workflows\WorkflowManager;

/**
 * Unit tests for RunWorkflowJob and WorkflowStepJob.
 *
 * WorkflowManager is a final class and cannot be mocked. For handle() tests
 * we construct a real WorkflowManager with a stub Dispatcher and verify delegation
 * by asserting that the manager receives the correct arguments (the unregistered
 * definition name triggers an InvalidArgumentException proving the args were forwarded).
 *
 * For failed() tests we bind a spy in the Laravel container since the jobs
 * resolve WorkflowManager via app().
 */
class WorkflowJobsTest extends TestCase
{
    private WorkflowManager $realManager;

    protected function setUp(): void
    {
        parent::setUp();

        $dispatcher = $this->createStub(Dispatcher::class);
        $this->realManager = new WorkflowManager($dispatcher);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    // -------------------------------------------------------
    // RunWorkflowJob
    // -------------------------------------------------------

    public function testRunWorkflowJobImplementsShouldQueue(): void
    {
        $job = new RunWorkflowJob('order-processing', 'inst-001');

        $this->assertInstanceOf(ShouldQueue::class, $job);
    }

    public function testRunWorkflowJobConstructorSetsProperties(): void
    {
        $job = new RunWorkflowJob('order-processing', 'inst-001');

        $this->assertSame('order-processing', $job->definitionName);
        $this->assertSame('inst-001', $job->instanceId);
        $this->assertSame('inst-001', $job->stationWorkflowInstanceId);
        $this->assertNull($job->stationWorkflowStepName);
    }

    public function testRunWorkflowJobHandleCallsExecuteExistingInstance(): void
    {
        $definitionName = 'email-campaign';
        $instanceId = 'inst-abc';
        $job = new RunWorkflowJob($definitionName, $instanceId);

        // When the definition is not registered, executeExistingInstance throws
        // InvalidArgumentException with the exact definition name -- proving
        // that handle() forwarded the correct arguments to the manager.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow '{$definitionName}' is not defined");

        $job->handle($this->realManager);
    }

    public function testRunWorkflowJobFailedCallsHandleAsyncStepFailure(): void
    {
        $job = new RunWorkflowJob('data-pipeline', 'inst-xyz');
        $spy = $this->createSpy();
        $this->bindSpyInContainer($spy);

        $job->failed(new RuntimeException('Something went wrong'));

        $this->assertSame('handleAsyncStepFailure', $spy->lastMethod);
        $this->assertSame(['inst-xyz', '_starter', 'Something went wrong'], $spy->lastArgs);
    }

    public function testRunWorkflowJobFailedPassesStarterAsStepName(): void
    {
        $job = new RunWorkflowJob('report-gen', 'inst-999');
        $spy = $this->createSpy();
        $this->bindSpyInContainer($spy);

        $job->failed(new RuntimeException('DB connection lost'));

        $this->assertSame(
            '_starter',
            $spy->lastArgs[1],
            'RunWorkflowJob must pass _starter as the step name in failed()',
        );
    }

    // -------------------------------------------------------
    // WorkflowStepJob
    // -------------------------------------------------------

    public function testWorkflowStepJobImplementsShouldQueue(): void
    {
        $job = new WorkflowStepJob('inst-001', 'send-email', 'notification-flow');

        $this->assertInstanceOf(ShouldQueue::class, $job);
    }

    public function testWorkflowStepJobConstructorSetsProperties(): void
    {
        $job = new WorkflowStepJob('inst-002', 'validate-data', 'etl-pipeline');

        $this->assertSame('inst-002', $job->instanceId);
        $this->assertSame('validate-data', $job->stepName);
        $this->assertSame('etl-pipeline', $job->definitionName);
        $this->assertSame('inst-002', $job->stationWorkflowInstanceId);
        $this->assertSame('validate-data', $job->stationWorkflowStepName);
    }

    public function testWorkflowStepJobHandleCallsExecuteAsyncStep(): void
    {
        $definitionName = 'etl-pipeline';
        $instanceId = 'inst-010';
        $stepName = 'transform';
        $job = new WorkflowStepJob($instanceId, $stepName, $definitionName);

        // When the definition is not registered, executeAsyncStep throws
        // InvalidArgumentException with the exact definition name -- proving
        // that handle() forwarded the correct arguments to the manager.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow '{$definitionName}' is not defined");

        $job->handle($this->realManager);
    }

    public function testWorkflowStepJobFailedCallsHandleAsyncStepFailure(): void
    {
        $job = new WorkflowStepJob('inst-020', 'upload-results', 'report-flow');
        $spy = $this->createSpy();
        $this->bindSpyInContainer($spy);

        $job->failed(new RuntimeException('Disk full'));

        $this->assertSame('handleAsyncStepFailure', $spy->lastMethod);
        $this->assertSame(['inst-020', 'upload-results', 'Disk full'], $spy->lastArgs);
    }

    public function testWorkflowStepJobFailedPassesActualStepNameNotStarter(): void
    {
        $job = new WorkflowStepJob('inst-030', 'process-payment', 'checkout-flow');
        $spy = $this->createSpy();
        $this->bindSpyInContainer($spy);

        $job->failed(new RuntimeException('Payment gateway timeout'));

        // WorkflowStepJob must pass its own stepName, NOT '_starter'
        $this->assertSame('process-payment', $spy->lastArgs[1]);
        $this->assertNotSame('_starter', $spy->lastArgs[1]);
    }

    /**
     * Create a spy that records method calls, used as a stand-in for
     * WorkflowManager when resolved via app() in failed() methods.
     */
    private function createSpy(): object
    {
        return new class {
            public ?string $lastMethod = null;

            /** @var array<int, mixed> */
            public array $lastArgs = [];

            public int $callCount = 0;

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $name, array $arguments): void
            {
                $this->lastMethod = $name;
                $this->lastArgs = $arguments;
                $this->callCount++;
            }
        };
    }

    /**
     * Bind a spy in the Laravel container so app(WorkflowManager::class) returns it.
     */
    private function bindSpyInContainer(object $spy): void
    {
        $container = new Container();
        $container->instance(WorkflowManager::class, $spy);
        Container::setInstance($container);
    }
}
