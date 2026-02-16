<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Core;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Station\Core\Workflow;
use Station\StationServiceProvider;

/**
 * Job class for testing workflow dispatch.
 */
class WorkflowDispatchTestJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public ?string $workflowId = null;

    public ?string $workflowName = null;

    public ?string $workflowStep = null;

    public function __construct(
        public readonly string $name = 'test',
    ) {}

    public function handle(): void {}
}

/**
 * Job without workflow properties.
 */
class WorkflowDispatchJobWithoutProperties implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $name = 'test',
    ) {}

    public function handle(): void {}
}

class WorkflowDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create job_batches table for Bus::batch()
        $this->app['db']->connection()->getSchemaBuilder()->create('job_batches', static function ($table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->text('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }

    public function testDispatchReturnsWorkflowId(): void
    {
        Bus::fake();

        $workflow = Workflow::create('test-workflow')
            ->add('step1', new WorkflowDispatchTestJob('job1'));

        $id = $workflow->dispatch();

        $this->assertSame($workflow->getId(), $id);
    }

    public function testDispatchWithLinearDependencies(): void
    {
        Bus::fake();

        $workflow = Workflow::create('linear')
            ->add('step1', new WorkflowDispatchTestJob('job1'))
            ->add('step2', new WorkflowDispatchTestJob('job2'), ['step1'])
            ->add('step3', new WorkflowDispatchTestJob('job3'), ['step2']);

        $id = $workflow->dispatch();

        $this->assertNotEmpty($id);
        Bus::assertBatched(static function ($batch) {
            // Verify the batch contains jobs from the workflow
            return $batch->jobs->count() >= 1;
        });
    }

    public function testDispatchWithParallelSteps(): void
    {
        Bus::fake();

        $workflow = Workflow::create('parallel')
            ->add('step1', new WorkflowDispatchTestJob('job1'))
            ->add('step2', new WorkflowDispatchTestJob('job2'))
            ->add('step3', new WorkflowDispatchTestJob('job3'));

        $id = $workflow->dispatch();

        $this->assertNotEmpty($id);
    }

    public function testDispatchWithDiamondPattern(): void
    {
        Bus::fake();

        // Diamond: A → B,C → D
        $workflow = Workflow::create('diamond')
            ->add('A', new WorkflowDispatchTestJob('A'))
            ->add('B', new WorkflowDispatchTestJob('B'), ['A'])
            ->add('C', new WorkflowDispatchTestJob('C'), ['A'])
            ->add('D', new WorkflowDispatchTestJob('D'), ['B', 'C']);

        $id = $workflow->dispatch();

        $this->assertNotEmpty($id);
    }

    public function testDispatchWithFanOutPattern(): void
    {
        Bus::fake();

        // Fan-out: A → B, C, D, E
        $workflow = Workflow::create('fan-out')
            ->add('A', new WorkflowDispatchTestJob('A'))
            ->add('B', new WorkflowDispatchTestJob('B'), ['A'])
            ->add('C', new WorkflowDispatchTestJob('C'), ['A'])
            ->add('D', new WorkflowDispatchTestJob('D'), ['A'])
            ->add('E', new WorkflowDispatchTestJob('E'), ['A']);

        $id = $workflow->dispatch();

        $this->assertNotEmpty($id);
    }

    public function testDispatchWithFanInPattern(): void
    {
        Bus::fake();

        // Fan-in: A, B, C, D → E
        $workflow = Workflow::create('fan-in')
            ->add('A', new WorkflowDispatchTestJob('A'))
            ->add('B', new WorkflowDispatchTestJob('B'))
            ->add('C', new WorkflowDispatchTestJob('C'))
            ->add('D', new WorkflowDispatchTestJob('D'))
            ->add('E', new WorkflowDispatchTestJob('E'), ['A', 'B', 'C', 'D']);

        $id = $workflow->dispatch();

        $this->assertNotEmpty($id);
    }

    public function testDispatchWithConnection(): void
    {
        Bus::fake();

        $workflow = Workflow::create('test')
            ->add('step1', new WorkflowDispatchTestJob('job1'))
            ->onConnection('redis');

        $id = $workflow->dispatch();

        $this->assertNotEmpty($id);
    }

    public function testDispatchWithQueue(): void
    {
        Bus::fake();

        $workflow = Workflow::create('test')
            ->add('step1', new WorkflowDispatchTestJob('job1'))
            ->onQueue('high-priority');

        $id = $workflow->dispatch();

        $this->assertNotEmpty($id);
    }

    public function testDispatchWithThenCallback(): void
    {
        Bus::fake();

        $workflow = Workflow::create('test')
            ->add('step1', new WorkflowDispatchTestJob('job1'))
            ->then(static fn() => null);

        $id = $workflow->dispatch();

        $this->assertNotEmpty($id);
    }

    public function testDispatchWithCatchCallback(): void
    {
        Bus::fake();

        $workflow = Workflow::create('test')
            ->add('step1', new WorkflowDispatchTestJob('job1'))
            ->catch(static fn() => null);

        $id = $workflow->dispatch();

        $this->assertNotEmpty($id);
    }

    public function testDispatchWithFinallyCallback(): void
    {
        Bus::fake();

        $workflow = Workflow::create('test')
            ->add('step1', new WorkflowDispatchTestJob('job1'))
            ->finally(static fn() => null);

        $id = $workflow->dispatch();

        $this->assertNotEmpty($id);
    }

    public function testDispatchWithAllOptions(): void
    {
        Bus::fake();

        $workflow = Workflow::create('full-workflow')
            ->add('step1', new WorkflowDispatchTestJob('job1'))
            ->add('step2', new WorkflowDispatchTestJob('job2'), ['step1'])
            ->onConnection('redis')
            ->onQueue('workflows')
            ->then(static fn() => null)
            ->catch(static fn() => null)
            ->finally(static fn() => null);

        $id = $workflow->dispatch();

        $this->assertSame($workflow->getId(), $id);
    }

    public function testDispatchSetsWorkflowMetadataOnJobs(): void
    {
        Bus::fake();

        $job1 = new WorkflowDispatchTestJob('job1');
        $job2 = new WorkflowDispatchTestJob('job2');

        $workflow = Workflow::create('metadata-test')
            ->add('step1', $job1)
            ->add('step2', $job2, ['step1']);

        $workflow->dispatch();

        // The buildBatch method should have set metadata on jobs
        $this->assertSame($workflow->getId(), $job1->workflowId);
        $this->assertSame('metadata-test', $job1->workflowName);
        $this->assertSame('step1', $job1->workflowStep);

        $this->assertSame($workflow->getId(), $job2->workflowId);
        $this->assertSame('metadata-test', $job2->workflowName);
        $this->assertSame('step2', $job2->workflowStep);
    }

    public function testDispatchWithJobsWithoutWorkflowProperties(): void
    {
        Bus::fake();

        $job = new WorkflowDispatchJobWithoutProperties('job1');

        $workflow = Workflow::create('test')
            ->add('step1', $job);

        $id = $workflow->dispatch();

        // Should not throw even without workflow properties
        $this->assertNotEmpty($id);
    }

    public function testDispatchWithCircularDependencyThrowsException(): void
    {
        Bus::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Workflow contains circular dependencies');

        // Circular: A → B → C → A
        $workflow = Workflow::create('circular')
            ->add('A', new WorkflowDispatchTestJob('A'), ['C'])
            ->add('B', new WorkflowDispatchTestJob('B'), ['A'])
            ->add('C', new WorkflowDispatchTestJob('C'), ['B']);

        $workflow->dispatch();
    }

    public function testDispatchEmptyWorkflow(): void
    {
        Bus::fake();

        $workflow = Workflow::create('empty');

        $id = $workflow->dispatch();

        $this->assertNotEmpty($id);
    }

    public function testDispatchWithSingleJob(): void
    {
        Bus::fake();

        $workflow = Workflow::create('single')
            ->add('only', new WorkflowDispatchTestJob('only'));

        $id = $workflow->dispatch();

        $this->assertNotEmpty($id);
    }

    public function testMultipleWorkflowsHaveIndependentIds(): void
    {
        Bus::fake();

        $workflow1 = Workflow::create('w1')
            ->add('step1', new WorkflowDispatchTestJob('job1'));
        $workflow2 = Workflow::create('w2')
            ->add('step1', new WorkflowDispatchTestJob('job2'));

        $id1 = $workflow1->dispatch();
        $id2 = $workflow2->dispatch();

        $this->assertNotSame($id1, $id2);
    }

    public function testDispatchWithComplexDag(): void
    {
        Bus::fake();

        // Complex DAG:
        //   A ──→ B ──→ D
        //   │           ↑
        //   └──→ C ─────┘
        //        │
        //        └──→ E
        $workflow = Workflow::create('complex-dag')
            ->add('A', new WorkflowDispatchTestJob('A'))
            ->add('B', new WorkflowDispatchTestJob('B'), ['A'])
            ->add('C', new WorkflowDispatchTestJob('C'), ['A'])
            ->add('D', new WorkflowDispatchTestJob('D'), ['B', 'C'])
            ->add('E', new WorkflowDispatchTestJob('E'), ['C']);

        $id = $workflow->dispatch();

        $this->assertNotEmpty($id);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }
}
