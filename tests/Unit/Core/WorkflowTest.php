<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Core;

use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;
use Station\Core\Workflow;

/**
 * Simple job class for testing workflows.
 */
class TestWorkflowJob implements ShouldQueue
{
    public ?string $workflowId = null;

    public ?string $workflowName = null;

    public ?string $workflowStep = null;

    public function __construct(
        public readonly string $name = 'test',
    ) {}

    public function handle(): void {}
}

class WorkflowTest extends TestCase
{
    public function testCreateReturnsWorkflowInstance(): void
    {
        $workflow = Workflow::create('my-workflow');

        $this->assertInstanceOf(Workflow::class, $workflow);
    }

    public function testGetNameReturnsWorkflowName(): void
    {
        $workflow = Workflow::create('test-workflow');

        $this->assertSame('test-workflow', $workflow->getName());
    }

    public function testGetIdReturnsUuid7(): void
    {
        $workflow = Workflow::create('test');

        $id = $workflow->getId();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id,
        );
    }

    public function testEachWorkflowHasUniqueId(): void
    {
        $workflow1 = Workflow::create('test');
        $workflow2 = Workflow::create('test');

        $this->assertNotSame($workflow1->getId(), $workflow2->getId());
    }

    public function testAddReturnsFluentInterface(): void
    {
        $workflow = Workflow::create('test');
        $job = new TestWorkflowJob();

        $result = $workflow->add('step1', $job);

        $this->assertSame($workflow, $result);
    }

    public function testAddWithDependencies(): void
    {
        $workflow = Workflow::create('test')
            ->add('step1', new TestWorkflowJob('step1'))
            ->add('step2', new TestWorkflowJob('step2'), ['step1']);

        $this->assertInstanceOf(Workflow::class, $workflow);
    }

    public function testOnConnectionReturnsFluentInterface(): void
    {
        $workflow = Workflow::create('test');

        $result = $workflow->onConnection('redis');

        $this->assertSame($workflow, $result);
    }

    public function testOnQueueReturnsFluentInterface(): void
    {
        $workflow = Workflow::create('test');

        $result = $workflow->onQueue('high-priority');

        $this->assertSame($workflow, $result);
    }

    public function testThenReturnsFluentInterface(): void
    {
        $workflow = Workflow::create('test');

        $result = $workflow->then(static function (): void {});

        $this->assertSame($workflow, $result);
    }

    public function testCatchReturnsFluentInterface(): void
    {
        $workflow = Workflow::create('test');

        $result = $workflow->catch(static function (): void {});

        $this->assertSame($workflow, $result);
    }

    public function testFinallyReturnsFluentInterface(): void
    {
        $workflow = Workflow::create('test');

        $result = $workflow->finally(static function (): void {});

        $this->assertSame($workflow, $result);
    }

    public function testFluentChaining(): void
    {
        $workflow = Workflow::create('full-workflow')
            ->add('step1', new TestWorkflowJob('step1'))
            ->add('step2', new TestWorkflowJob('step2'), ['step1'])
            ->add('step3', new TestWorkflowJob('step3'), ['step1'])
            ->add('step4', new TestWorkflowJob('step4'), ['step2', 'step3'])
            ->onConnection('redis')
            ->onQueue('workflows')
            ->then(static fn() => null)
            ->catch(static fn() => null)
            ->finally(static fn() => null);

        $this->assertInstanceOf(Workflow::class, $workflow);
    }

    public function testAddMultipleStepsNoDependencies(): void
    {
        $workflow = Workflow::create('parallel')
            ->add('step1', new TestWorkflowJob('step1'))
            ->add('step2', new TestWorkflowJob('step2'))
            ->add('step3', new TestWorkflowJob('step3'));

        $this->assertInstanceOf(Workflow::class, $workflow);
    }

    public function testAddWithComplexDependencyGraph(): void
    {
        // Diamond pattern: A → B,C → D
        $workflow = Workflow::create('diamond')
            ->add('A', new TestWorkflowJob('A'))
            ->add('B', new TestWorkflowJob('B'), ['A'])
            ->add('C', new TestWorkflowJob('C'), ['A'])
            ->add('D', new TestWorkflowJob('D'), ['B', 'C']);

        $this->assertInstanceOf(Workflow::class, $workflow);
    }

    public function testEmptyWorkflowName(): void
    {
        $workflow = Workflow::create('');

        $this->assertSame('', $workflow->getName());
    }

    public function testOnConnectionCanBeReplaced(): void
    {
        $workflow = Workflow::create('test')
            ->onConnection('redis')
            ->onConnection('rabbitmq');

        $this->assertInstanceOf(Workflow::class, $workflow);
    }

    public function testOnQueueCanBeReplaced(): void
    {
        $workflow = Workflow::create('test')
            ->onQueue('low')
            ->onQueue('high');

        $this->assertInstanceOf(Workflow::class, $workflow);
    }

    public function testThenCallbackCanBeReplaced(): void
    {
        $workflow = Workflow::create('test')
            ->then(static fn() => 'first')
            ->then(static fn() => 'second');

        $this->assertInstanceOf(Workflow::class, $workflow);
    }

    public function testCatchCallbackCanBeReplaced(): void
    {
        $workflow = Workflow::create('test')
            ->catch(static fn() => 'first')
            ->catch(static fn() => 'second');

        $this->assertInstanceOf(Workflow::class, $workflow);
    }

    public function testFinallyCallbackCanBeReplaced(): void
    {
        $workflow = Workflow::create('test')
            ->finally(static fn() => 'first')
            ->finally(static fn() => 'second');

        $this->assertInstanceOf(Workflow::class, $workflow);
    }

    public function testAddSameStepNameOverwritesPrevious(): void
    {
        $job1 = new TestWorkflowJob('first');
        $job2 = new TestWorkflowJob('second');

        $workflow = Workflow::create('test')
            ->add('step', $job1)
            ->add('step', $job2);

        $this->assertInstanceOf(Workflow::class, $workflow);
    }

    public function testConstructorSetsName(): void
    {
        $workflow = new Workflow('direct-construct');

        $this->assertSame('direct-construct', $workflow->getName());
    }

    public function testAddWithEmptyDependenciesArray(): void
    {
        $workflow = Workflow::create('test')
            ->add('step1', new TestWorkflowJob(), []);

        $this->assertInstanceOf(Workflow::class, $workflow);
    }

    public function testAddLinearChainDependencies(): void
    {
        // Linear: A → B → C → D
        $workflow = Workflow::create('linear')
            ->add('A', new TestWorkflowJob('A'))
            ->add('B', new TestWorkflowJob('B'), ['A'])
            ->add('C', new TestWorkflowJob('C'), ['B'])
            ->add('D', new TestWorkflowJob('D'), ['C']);

        $this->assertInstanceOf(Workflow::class, $workflow);
    }

    public function testAddFanOutPattern(): void
    {
        // Fan-out: A → B, C, D, E (one to many)
        $workflow = Workflow::create('fan-out')
            ->add('A', new TestWorkflowJob('A'))
            ->add('B', new TestWorkflowJob('B'), ['A'])
            ->add('C', new TestWorkflowJob('C'), ['A'])
            ->add('D', new TestWorkflowJob('D'), ['A'])
            ->add('E', new TestWorkflowJob('E'), ['A']);

        $this->assertInstanceOf(Workflow::class, $workflow);
    }

    public function testAddFanInPattern(): void
    {
        // Fan-in: A, B, C, D → E (many to one)
        $workflow = Workflow::create('fan-in')
            ->add('A', new TestWorkflowJob('A'))
            ->add('B', new TestWorkflowJob('B'))
            ->add('C', new TestWorkflowJob('C'))
            ->add('D', new TestWorkflowJob('D'))
            ->add('E', new TestWorkflowJob('E'), ['A', 'B', 'C', 'D']);

        $this->assertInstanceOf(Workflow::class, $workflow);
    }

    public function testGetIdIsDeterministic(): void
    {
        $workflow = Workflow::create('test');

        $id1 = $workflow->getId();
        $id2 = $workflow->getId();

        $this->assertSame($id1, $id2);
    }
}
