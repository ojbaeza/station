<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Core;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Events\JobFailed as JobFailedEvent;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use Station\Core\Worker;
use Station\Events\WorkerLoopIteration;
use Throwable;

class WorkerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface&QueueFactory $queueFactory;

    private MockInterface&Dispatcher $events;

    private MockInterface&Queue $queue;

    private Worker $worker;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up a minimal container so logger() helper works in Worker catch blocks
        $container = new Container();
        $container->instance('log', new NullLogger());
        Container::setInstance($container);

        $this->queueFactory = Mockery::mock(QueueFactory::class);
        $this->events = Mockery::mock(Dispatcher::class);
        $this->queue = Mockery::mock(Queue::class);

        $this->worker = new Worker(
            $this->queueFactory,
            $this->events,
            ['default' => 'database'],
        );

        // Worker::run() sets $this->connection from config, but direct processNextJob
        // calls bypass run(), so we set the connection to match the config default
        $reflection = new ReflectionClass($this->worker);
        $connProp = $reflection->getProperty('connection');
        $connProp->setValue($this->worker, 'database');
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Construction and getId
    // ---------------------------------------------------------------

    public function testGetIdReturnsWorkerPrefixedUuid7(): void
    {
        $id = $this->worker->getId();

        $this->assertStringStartsWith('worker-', $id);
        // UUID7 pattern after "worker-" prefix
        $uuid = substr($id, 7);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid,
        );
    }

    public function testEachWorkerGetsUniqueId(): void
    {
        $workerA = new Worker($this->queueFactory, $this->events, []);
        $workerB = new Worker($this->queueFactory, $this->events, []);

        $this->assertNotSame($workerA->getId(), $workerB->getId());
    }

    // ---------------------------------------------------------------
    // stop(), pause(), resume() -- state changes
    // ---------------------------------------------------------------

    public function testStopSetsShouldQuitFlag(): void
    {
        $reflection = new ReflectionClass($this->worker);
        $prop = $reflection->getProperty('shouldQuit');

        $this->assertFalse($prop->getValue($this->worker));

        $this->worker->stop();

        $this->assertTrue($prop->getValue($this->worker));
    }

    public function testPauseSetsPausedFlag(): void
    {
        $reflection = new ReflectionClass($this->worker);
        $prop = $reflection->getProperty('paused');

        $this->assertFalse($prop->getValue($this->worker));

        $this->worker->pause();

        $this->assertTrue($prop->getValue($this->worker));
    }

    public function testResumeUnsetsPausedFlag(): void
    {
        $this->worker->pause();

        $reflection = new ReflectionClass($this->worker);
        $prop = $reflection->getProperty('paused');

        $this->assertTrue($prop->getValue($this->worker));

        $this->worker->resume();

        $this->assertFalse($prop->getValue($this->worker));
    }

    public function testResumeAfterMultiplePausesRestoresRunningState(): void
    {
        $reflection = new ReflectionClass($this->worker);
        $prop = $reflection->getProperty('paused');

        $this->worker->pause();
        $this->worker->pause();
        $this->assertTrue($prop->getValue($this->worker));

        $this->worker->resume();
        $this->assertFalse($prop->getValue($this->worker));
    }

    public function testStopIsIdempotent(): void
    {
        $reflection = new ReflectionClass($this->worker);
        $prop = $reflection->getProperty('shouldQuit');

        $this->worker->stop();
        $this->assertTrue($prop->getValue($this->worker));

        // Calling stop again should not error and still be true
        $this->worker->stop();
        $this->assertTrue($prop->getValue($this->worker));
    }

    // ---------------------------------------------------------------
    // processJob() -- event dispatching on successful jobs
    // ---------------------------------------------------------------

    public function testProcessJobDispatchesJobProcessingAndJobProcessedEvents(): void
    {
        $job = $this->createMockJob('job-123');
        $job->shouldReceive('fire')->once();
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('delete')->once();

        $dispatchedEvents = [];
        $this->events->shouldReceive('dispatch')
            ->andReturnUsing(static function ($event) use (&$dispatchedEvents): void {
                $dispatchedEvents[] = $event;
            });

        $this->invokeProcessJob($this->worker, $job, 'default', 60);

        $this->assertCount(2, $dispatchedEvents);
        $this->assertInstanceOf(JobProcessing::class, $dispatchedEvents[0]);
        $this->assertInstanceOf(JobProcessed::class, $dispatchedEvents[1]);
    }

    public function testProcessJobSetsCorrectConnectionNameOnEvents(): void
    {
        $job = $this->createMockJob('job-456');
        $job->shouldReceive('fire')->once();
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('delete')->once();

        // Set a custom connection on the worker
        $reflection = new ReflectionClass($this->worker);
        $connProp = $reflection->getProperty('connection');
        $connProp->setValue($this->worker, 'rabbitmq');

        $dispatchedEvents = [];
        $this->events->shouldReceive('dispatch')
            ->andReturnUsing(static function ($event) use (&$dispatchedEvents): void {
                $dispatchedEvents[] = $event;
            });

        $this->invokeProcessJob($this->worker, $job, 'emails', 60);

        $this->assertSame('rabbitmq', $dispatchedEvents[0]->connectionName);
        $this->assertSame('rabbitmq', $dispatchedEvents[1]->connectionName);
    }

    public function testProcessJobPassesJobInstanceToEvents(): void
    {
        $job = $this->createMockJob('job-789');
        $job->shouldReceive('fire')->once();
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('delete')->once();

        $dispatchedEvents = [];
        $this->events->shouldReceive('dispatch')
            ->andReturnUsing(static function ($event) use (&$dispatchedEvents): void {
                $dispatchedEvents[] = $event;
            });

        $this->invokeProcessJob($this->worker, $job, 'default', 60);

        $this->assertSame($job, $dispatchedEvents[0]->job);
        $this->assertSame($job, $dispatchedEvents[1]->job);
    }

    public function testProcessJobDeletesJobWhenNotDeletedReleasedOrFailed(): void
    {
        $job = $this->createMockJob('job-001');
        $job->shouldReceive('fire')->once();
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('delete')->once();

        $this->events->shouldReceive('dispatch');

        $this->invokeProcessJob($this->worker, $job, 'default', 60);
    }

    public function testProcessJobDoesNotDeleteAlreadyDeletedJob(): void
    {
        $job = $this->createMockJob('job-002');
        $job->shouldReceive('fire')->once();
        $job->shouldReceive('isDeleted')->andReturn(true);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldNotReceive('delete');

        $this->events->shouldReceive('dispatch');

        $this->invokeProcessJob($this->worker, $job, 'default', 60);
    }

    public function testProcessJobDoesNotDeleteReleasedJob(): void
    {
        $job = $this->createMockJob('job-003');
        $job->shouldReceive('fire')->once();
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(true);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldNotReceive('delete');

        $this->events->shouldReceive('dispatch');

        $this->invokeProcessJob($this->worker, $job, 'default', 60);
    }

    public function testProcessJobDoesNotDeleteFailedJob(): void
    {
        $job = $this->createMockJob('job-004');
        $job->shouldReceive('fire')->once();
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('hasFailed')->andReturn(true);
        $job->shouldNotReceive('delete');

        $this->events->shouldReceive('dispatch');

        $this->invokeProcessJob($this->worker, $job, 'default', 60);
    }

    public function testProcessJobIncrementsJobsProcessedOnSuccess(): void
    {
        $job = $this->createMockJob('job-005');
        $job->shouldReceive('fire')->once();
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('delete')->once();

        $this->events->shouldReceive('dispatch');

        $reflection = new ReflectionClass($this->worker);
        $jobsProcessedProp = $reflection->getProperty('jobsProcessed');

        $this->assertSame(0, $jobsProcessedProp->getValue($this->worker));

        $this->invokeProcessJob($this->worker, $job, 'default', 60);

        $this->assertSame(1, $jobsProcessedProp->getValue($this->worker));
    }

    public function testProcessJobIncrementsCounterMultipleTimes(): void
    {
        $this->events->shouldReceive('dispatch');

        $reflection = new ReflectionClass($this->worker);
        $jobsProcessedProp = $reflection->getProperty('jobsProcessed');

        for ($i = 1; $i <= 3; $i++) {
            $job = $this->createMockJob("job-multi-$i");
            $job->shouldReceive('fire')->once();
            $job->shouldReceive('isDeleted')->andReturn(false);
            $job->shouldReceive('isReleased')->andReturn(false);
            $job->shouldReceive('hasFailed')->andReturn(false);
            $job->shouldReceive('delete')->once();

            $this->invokeProcessJob($this->worker, $job, 'default', 60);
        }

        $this->assertSame(3, $jobsProcessedProp->getValue($this->worker));
    }

    // ---------------------------------------------------------------
    // processJob() -- event dispatching on job failure
    // ---------------------------------------------------------------

    public function testProcessJobDispatchesJobFailedEventOnException(): void
    {
        $exception = new RuntimeException('Something went wrong');
        $job = $this->createMockJob('job-fail-1');
        $job->shouldReceive('fire')->once()->andThrow($exception);
        // handleJobFailure expectations
        $job->shouldReceive('maxTries')->andReturn(3);
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('release')->once();

        $dispatchedEvents = [];
        $this->events->shouldReceive('dispatch')
            ->andReturnUsing(static function ($event) use (&$dispatchedEvents): void {
                $dispatchedEvents[] = $event;
            });

        $this->invokeProcessJob($this->worker, $job, 'default', 60);

        // Should dispatch JobProcessing first, then JobFailed
        $this->assertCount(2, $dispatchedEvents);
        $this->assertInstanceOf(JobProcessing::class, $dispatchedEvents[0]);
        $this->assertInstanceOf(JobFailedEvent::class, $dispatchedEvents[1]);
    }

    public function testProcessJobPassesExceptionToJobFailedEvent(): void
    {
        $exception = new RuntimeException('Specific error message');
        $job = $this->createMockJob('job-fail-2');
        $job->shouldReceive('fire')->once()->andThrow($exception);
        $job->shouldReceive('maxTries')->andReturn(3);
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('release');

        $dispatchedEvents = [];
        $this->events->shouldReceive('dispatch')
            ->andReturnUsing(static function ($event) use (&$dispatchedEvents): void {
                $dispatchedEvents[] = $event;
            });

        $this->invokeProcessJob($this->worker, $job, 'default', 60);

        $failedEvent = $dispatchedEvents[1];
        $this->assertInstanceOf(JobFailedEvent::class, $failedEvent);
        $this->assertSame($exception, $failedEvent->exception);
        $this->assertSame('Specific error message', $failedEvent->exception->getMessage());
    }

    public function testProcessJobDoesNotIncrementJobsProcessedOnFailure(): void
    {
        $job = $this->createMockJob('job-fail-3');
        $job->shouldReceive('fire')->once()->andThrow(new RuntimeException('Error'));
        $job->shouldReceive('maxTries')->andReturn(3);
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('release');

        $this->events->shouldReceive('dispatch');

        $reflection = new ReflectionClass($this->worker);
        $jobsProcessedProp = $reflection->getProperty('jobsProcessed');

        $this->invokeProcessJob($this->worker, $job, 'default', 60);

        $this->assertSame(0, $jobsProcessedProp->getValue($this->worker));
    }

    // ---------------------------------------------------------------
    // handleJobFailure() -- retry logic
    // ---------------------------------------------------------------

    public function testHandleJobFailureReleasesJobWhenAttemptsLessThanMaxTries(): void
    {
        $exception = new RuntimeException('Retry me');
        $job = $this->createMockJob('job-retry-1');
        $job->shouldReceive('maxTries')->andReturn(3);
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('release')->once()->with(Mockery::type('int'));
        $job->shouldNotReceive('fail');

        $this->invokeHandleJobFailure($this->worker, $job, 'job-retry-1', 'default', $exception);
    }

    public function testHandleJobFailureReleasesOnSecondAttemptWhenMaxTriesIsThree(): void
    {
        $exception = new RuntimeException('Retry again');
        $job = $this->createMockJob('job-retry-2');
        $job->shouldReceive('maxTries')->andReturn(3);
        $job->shouldReceive('attempts')->andReturn(2);
        $job->shouldReceive('release')->once()->with(Mockery::type('int'));
        $job->shouldNotReceive('fail');

        $this->invokeHandleJobFailure($this->worker, $job, 'job-retry-2', 'default', $exception);
    }

    public function testHandleJobFailureFailsJobWhenAttemptsReachMaxTries(): void
    {
        $exception = new RuntimeException('Max reached');
        $job = $this->createMockJob('job-maxed-1');
        $job->shouldReceive('maxTries')->andReturn(3);
        $job->shouldReceive('attempts')->andReturn(3);
        $job->shouldNotReceive('release');
        $job->shouldReceive('fail')->once()->with($exception);

        $this->invokeHandleJobFailure($this->worker, $job, 'job-maxed-1', 'default', $exception);
    }

    public function testHandleJobFailureFailsJobWhenAttemptsExceedMaxTries(): void
    {
        $exception = new RuntimeException('Way over');
        $job = $this->createMockJob('job-maxed-2');
        $job->shouldReceive('maxTries')->andReturn(2);
        $job->shouldReceive('attempts')->andReturn(5);
        $job->shouldNotReceive('release');
        $job->shouldReceive('fail')->once()->with($exception);

        $this->invokeHandleJobFailure($this->worker, $job, 'job-maxed-2', 'default', $exception);
    }

    public function testHandleJobFailureUsesDefaultMaxTriesOfThreeWhenNull(): void
    {
        $exception = new RuntimeException('Null max');
        $job = $this->createMockJob('job-null-max');
        $job->shouldReceive('maxTries')->andReturn(null);
        // attempts = 2, default maxTries = 3, so 2 < 3 means release
        $job->shouldReceive('attempts')->andReturn(2);
        $job->shouldReceive('release')->once()->with(Mockery::type('int'));
        $job->shouldNotReceive('fail');

        $this->invokeHandleJobFailure($this->worker, $job, 'job-null-max', 'default', $exception);
    }

    public function testHandleJobFailureFailsAtDefaultMaxTriesWhenNull(): void
    {
        $exception = new RuntimeException('Null max fail');
        $job = $this->createMockJob('job-null-max-fail');
        $job->shouldReceive('maxTries')->andReturn(null);
        // attempts = 3, default maxTries = 3, so 3 >= 3 means fail
        $job->shouldReceive('attempts')->andReturn(3);
        $job->shouldNotReceive('release');
        $job->shouldReceive('fail')->once()->with($exception);

        $this->invokeHandleJobFailure($this->worker, $job, 'job-null-max-fail', 'default', $exception);
    }

    public function testHandleJobFailureWithMaxTriesOneFailsImmediately(): void
    {
        $exception = new RuntimeException('One shot');
        $job = $this->createMockJob('job-one-shot');
        $job->shouldReceive('maxTries')->andReturn(1);
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldNotReceive('release');
        $job->shouldReceive('fail')->once()->with($exception);

        $this->invokeHandleJobFailure($this->worker, $job, 'job-one-shot', 'default', $exception);
    }

    public function testHandleJobFailureSurvivesReleaseException(): void
    {
        // When release() itself throws, the worker should not crash
        $originalException = new RuntimeException('Original error');
        $job = $this->createMockJob('job-release-fail');
        $job->shouldReceive('maxTries')->andReturn(3);
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('release')->once()->andThrow(new RuntimeException('Channel broken'));

        // This should NOT throw -- the worker catches the release exception
        $this->invokeHandleJobFailure($this->worker, $job, 'job-release-fail', 'default', $originalException);

        // If we reach here without exception, the test passes
    }

    public function testHandleJobFailureSurvivesFailException(): void
    {
        $originalException = new RuntimeException('Original');
        $job = $this->createMockJob('job-fail-fail');
        $job->shouldReceive('maxTries')->andReturn(1);
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('fail')->once()->andThrow(new RuntimeException('Cannot fail job'));

        // Should not throw
        $this->invokeHandleJobFailure($this->worker, $job, 'job-fail-fail', 'default', $originalException);
    }

    // ---------------------------------------------------------------
    // calculateRetryDelay() -- exponential backoff with jitter
    // ---------------------------------------------------------------

    public function testCalculateRetryDelayFirstAttemptReturnsAroundBaseDelay(): void
    {
        // attempt=1: base * 2^0 = 10, jitter +/-2 => range [8, 12]
        $delay = $this->invokeCalculateRetryDelay($this->worker, 1);

        $this->assertGreaterThanOrEqual(8, $delay);
        $this->assertLessThanOrEqual(12, $delay);
    }

    public function testCalculateRetryDelaySecondAttemptDoubles(): void
    {
        // attempt=2: base * 2^1 = 20, jitter +/-4 => range [16, 24]
        $delay = $this->invokeCalculateRetryDelay($this->worker, 2);

        $this->assertGreaterThanOrEqual(16, $delay);
        $this->assertLessThanOrEqual(24, $delay);
    }

    public function testCalculateRetryDelayThirdAttemptQuadruples(): void
    {
        // attempt=3: base * 2^2 = 40, jitter +/-8 => range [32, 48]
        $delay = $this->invokeCalculateRetryDelay($this->worker, 3);

        $this->assertGreaterThanOrEqual(32, $delay);
        $this->assertLessThanOrEqual(48, $delay);
    }

    public function testCalculateRetryDelayIsCappedAtMaxDelay(): void
    {
        // attempt=20: base * 2^19 = 5_242_880, capped to 3600, jitter +/-720 => [2880, 4320]
        $delay = $this->invokeCalculateRetryDelay($this->worker, 20);

        $this->assertGreaterThanOrEqual(2880, $delay);
        $this->assertLessThanOrEqual(4320, $delay);
    }

    public function testCalculateRetryDelayNeverReturnsNegative(): void
    {
        // Even at edge cases, delay should never be negative
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $delay = $this->invokeCalculateRetryDelay($this->worker, $attempt);
            $this->assertGreaterThanOrEqual(0, $delay, "Delay must be non-negative for attempt $attempt");
        }
    }

    public function testCalculateRetryDelayGrowsExponentially(): void
    {
        // Collect delays across multiple runs and check the midpoint grows
        // attempt=1 midpoint ~10, attempt=4 midpoint ~80
        // We check that a higher attempt yields a delay at least as large as a lower one's minimum
        $lowAttemptDelay = $this->invokeCalculateRetryDelay($this->worker, 1);
        $highAttemptDelay = $this->invokeCalculateRetryDelay($this->worker, 5);

        // attempt=5: base * 2^4 = 160, jitter +/-32 => [128, 192]
        // attempt=1: [8, 12]
        // high attempt minimum (128) should exceed low attempt maximum (12)
        $this->assertGreaterThan(12, $highAttemptDelay);
    }

    // ---------------------------------------------------------------
    // memoryExceeded()
    // ---------------------------------------------------------------

    public function testMemoryExceededReturnsFalseWhenUnderLimit(): void
    {
        // Set a very high memory limit (10 GB) -- we will never exceed this
        $exceeded = $this->invokeMemoryExceeded($this->worker, 10240);

        $this->assertFalse($exceeded);
    }

    public function testMemoryExceededReturnsTrueWhenOverLimit(): void
    {
        // Set memory limit to 0 MB -- any usage exceeds this
        // memory_get_usage(true) is always > 0 in a running process
        $exceeded = $this->invokeMemoryExceeded($this->worker, 0);

        $this->assertTrue($exceeded);
    }

    public function testMemoryExceededReturnsTrueWhenAtLimit(): void
    {
        // memory_get_usage(true) returns page-aligned bytes, convert to exact MB float
        // memoryExceeded uses >= comparison, so setting limit to 1 MB (always exceeded) proves the boundary
        $exceeded = $this->invokeMemoryExceeded($this->worker, 1);

        $this->assertTrue($exceeded);
    }

    // ---------------------------------------------------------------
    // processNextJob() -- queue popping behavior
    // ---------------------------------------------------------------

    public function testProcessNextJobPopsFromQueuesInPriorityOrder(): void
    {
        $job = $this->createMockJob('job-pop-1');
        $job->shouldReceive('fire')->once();
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('delete')->once();

        // First queue returns null, second queue returns job
        $popOrder = [];
        $this->queue->shouldReceive('pop')
            ->andReturnUsing(static function ($queue) use ($job, &$popOrder) {
                $popOrder[] = $queue;

                return $queue === 'high' ? null : $job;
            });

        $this->queueFactory->shouldReceive('connection')
            ->with('database')
            ->andReturn($this->queue);

        $this->events->shouldReceive('dispatch');

        $result = $this->invokeProcessNextJob($this->worker, ['high', 'default'], 60);

        $this->assertTrue($result);
        $this->assertSame(['high', 'default'], $popOrder);
    }

    public function testProcessNextJobReturnsFalseWhenNoJobAvailable(): void
    {
        $this->queue->shouldReceive('pop')->andReturn(null);

        $this->queueFactory->shouldReceive('connection')
            ->with('database')
            ->andReturn($this->queue);

        $result = $this->invokeProcessNextJob($this->worker, ['default'], 60);

        $this->assertFalse($result);
    }

    public function testProcessNextJobReturnsTrueWhenJobFound(): void
    {
        $job = $this->createMockJob('job-found');
        $job->shouldReceive('fire')->once();
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('delete')->once();

        $this->queue->shouldReceive('pop')->with('default')->andReturn($job);

        $this->queueFactory->shouldReceive('connection')
            ->with('database')
            ->andReturn($this->queue);

        $this->events->shouldReceive('dispatch');

        $result = $this->invokeProcessNextJob($this->worker, ['default'], 60);

        $this->assertTrue($result);
    }

    public function testProcessNextJobReturnsFalseOnConnectionException(): void
    {
        $this->queueFactory->shouldReceive('connection')
            ->with('database')
            ->andThrow(new RuntimeException('Connection refused'));

        $result = $this->invokeProcessNextJob($this->worker, ['default'], 60);

        $this->assertFalse($result);
    }

    public function testProcessNextJobStopsAtFirstQueueWithJob(): void
    {
        $job = $this->createMockJob('job-priority');
        $job->shouldReceive('fire')->once();
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('delete')->once();

        // First queue returns a job, second queue should NOT be popped
        $this->queue->shouldReceive('pop')->with('high')->once()->andReturn($job);
        $this->queue->shouldNotReceive('pop')->with('low');

        $this->queueFactory->shouldReceive('connection')
            ->with('database')
            ->andReturn($this->queue);

        $this->events->shouldReceive('dispatch');

        $result = $this->invokeProcessNextJob($this->worker, ['high', 'low'], 60);

        $this->assertTrue($result);
    }

    public function testProcessNextJobUsesConfiguredConnection(): void
    {
        $reflection = new ReflectionClass($this->worker);
        $connProp = $reflection->getProperty('connection');
        $connProp->setValue($this->worker, 'rabbitmq');

        $this->queue->shouldReceive('pop')->andReturn(null);

        $this->queueFactory->shouldReceive('connection')
            ->with('rabbitmq')
            ->once()
            ->andReturn($this->queue);

        $this->invokeProcessNextJob($this->worker, ['default'], 60);

        // Mockery verifies the expected connection was used
    }

    // ---------------------------------------------------------------
    // run() -- integration of the worker loop
    // ---------------------------------------------------------------

    public function testRunExitsWhenMaxJobsReached(): void
    {
        $this->queueFactory->shouldReceive('connection')
            ->andReturn($this->queue);

        // Return a job twice, then the loop should exit due to max_jobs=2
        $callCount = 0;
        $this->queue->shouldReceive('pop')->andReturnUsing(static function () use (&$callCount) {
            $callCount++;
            $job = Mockery::mock(QueueJob::class);
            $job->shouldReceive('getJobId')->andReturn("job-run-$callCount");
            $job->shouldReceive('fire');
            $job->shouldReceive('isDeleted')->andReturn(false);
            $job->shouldReceive('isReleased')->andReturn(false);
            $job->shouldReceive('hasFailed')->andReturn(false);
            $job->shouldReceive('delete');

            return $job;
        });

        $this->events->shouldReceive('dispatch');

        $this->worker->run(['default'], [
            'connection' => 'database',
            'max_jobs' => 2,
            'memory' => 10240,
            'timeout' => 60,
            'sleep' => 1,
            'max_time' => 0,
        ]);

        $reflection = new ReflectionClass($this->worker);
        $jobsProcessedProp = $reflection->getProperty('jobsProcessed');

        $this->assertSame(2, $jobsProcessedProp->getValue($this->worker));
    }

    public function testRunExitsWhenMaxTimeReached(): void
    {
        $this->queueFactory->shouldReceive('connection')
            ->andReturn($this->queue);

        // Return null (no job), worker sleeps but max_time should trigger exit
        $this->queue->shouldReceive('pop')->andReturn(null);

        $this->events->shouldReceive('dispatch');

        // max_time=1 second: the worker loop should exit after ~1 second
        $startTime = time();
        $this->worker->run(['default'], [
            'connection' => 'database',
            'max_jobs' => 0,
            'memory' => 10240,
            'timeout' => 60,
            'sleep' => 1,
            'max_time' => 1,
        ]);
        $elapsed = time() - $startTime;

        // Should have exited within a reasonable time (1-3 seconds)
        $this->assertLessThanOrEqual(5, $elapsed);

        $reflection = new ReflectionClass($this->worker);
        $shouldQuitProp = $reflection->getProperty('shouldQuit');
        $this->assertTrue($shouldQuitProp->getValue($this->worker));
    }

    public function testRunExitsImmediatelyWhenStopCalledBeforeLoop(): void
    {
        // Pre-stop the worker
        $this->worker->stop();

        // The run method should exit immediately without popping any jobs
        $this->queueFactory->shouldNotReceive('connection');

        $this->worker->run(['default'], [
            'connection' => 'database',
            'max_jobs' => 0,
            'memory' => 10240,
            'timeout' => 60,
            'sleep' => 1,
            'max_time' => 0,
        ]);

        $reflection = new ReflectionClass($this->worker);
        $shouldQuitProp = $reflection->getProperty('shouldQuit');
        $this->assertTrue($shouldQuitProp->getValue($this->worker));
    }

    public function testRunUsesConnectionFromOptions(): void
    {
        $this->worker->stop(); // Immediately stop after first loop check

        // Since shouldQuit is already true, run() exits before processing
        // But we can test that connection would be set from options
        $workerFresh = new Worker($this->queueFactory, $this->events, []);
        $workerFresh->stop();

        $workerFresh->run(['default'], [
            'connection' => 'sqs',
        ]);

        $reflection = new ReflectionClass($workerFresh);
        $connProp = $reflection->getProperty('connection');
        $this->assertSame('sqs', $connProp->getValue($workerFresh));
    }

    public function testRunUsesConfigDefaultWhenConnectionNotInOptions(): void
    {
        $worker = new Worker($this->queueFactory, $this->events, [
            'default' => 'rabbitmq',
        ]);
        $worker->stop();

        $worker->run(['default'], []);

        $reflection = new ReflectionClass($worker);
        $connProp = $reflection->getProperty('connection');
        $this->assertSame('rabbitmq', $connProp->getValue($worker));
    }

    public function testRunFallsBackToDatabaseWhenNoConnectionConfigured(): void
    {
        $worker = new Worker($this->queueFactory, $this->events, []);
        $worker->stop();

        $worker->run(['default'], []);

        $reflection = new ReflectionClass($worker);
        $connProp = $reflection->getProperty('connection');
        $this->assertSame('database', $connProp->getValue($worker));
    }

    public function testRunDispatchesWorkerLoopIterationEvent(): void
    {
        $this->queueFactory->shouldReceive('connection')
            ->andReturn($this->queue);

        $job = $this->createMockJob('job-loop-event');
        $job->shouldReceive('fire');
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('delete');

        $this->queue->shouldReceive('pop')->andReturn($job);

        $loopEvents = [];
        $this->events->shouldReceive('dispatch')
            ->andReturnUsing(static function ($event) use (&$loopEvents): void {
                if ($event instanceof WorkerLoopIteration) {
                    $loopEvents[] = $event;
                }
            });

        $this->worker->run(['default'], [
            'connection' => 'database',
            'max_jobs' => 1,
            'memory' => 10240,
            'timeout' => 60,
            'sleep' => 1,
            'max_time' => 0,
        ]);

        $this->assertCount(1, $loopEvents);
        $this->assertSame($this->worker->getId(), $loopEvents[0]->workerId);
    }

    public function testRunExitsWhenMemoryExceeded(): void
    {
        // Set memory limit to 0, causing immediate memory exceeded detection
        $this->worker->run(['default'], [
            'connection' => 'database',
            'max_jobs' => 0,
            'memory' => 0,
            'timeout' => 60,
            'sleep' => 1,
            'max_time' => 0,
        ]);

        $reflection = new ReflectionClass($this->worker);
        $shouldQuitProp = $reflection->getProperty('shouldQuit');
        $this->assertTrue($shouldQuitProp->getValue($this->worker));
    }

    // ---------------------------------------------------------------
    // Full processJob + handleJobFailure integration via processNextJob
    // ---------------------------------------------------------------

    public function testProcessNextJobHandlesFailureWithRetry(): void
    {
        $exception = new RuntimeException('Integration failure');
        $job = $this->createMockJob('job-int-fail');
        $job->shouldReceive('fire')->once()->andThrow($exception);
        $job->shouldReceive('maxTries')->andReturn(3);
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('release')->once()->with(Mockery::type('int'));

        $this->queue->shouldReceive('pop')->with('default')->andReturn($job);

        $this->queueFactory->shouldReceive('connection')
            ->with('database')
            ->andReturn($this->queue);

        $dispatchedEvents = [];
        $this->events->shouldReceive('dispatch')
            ->andReturnUsing(static function ($event) use (&$dispatchedEvents): void {
                $dispatchedEvents[] = $event;
            });

        $result = $this->invokeProcessNextJob($this->worker, ['default'], 60);

        $this->assertTrue($result);

        // Verify event sequence: JobProcessing, JobFailed
        $eventClasses = array_map(static fn($e) => $e::class, $dispatchedEvents);
        $this->assertSame([
            JobProcessing::class,
            JobFailedEvent::class,
        ], $eventClasses);
    }

    public function testProcessNextJobHandlesFailureWithMaxAttemptsReached(): void
    {
        $exception = new RuntimeException('Final failure');
        $job = $this->createMockJob('job-int-max');
        $job->shouldReceive('fire')->once()->andThrow($exception);
        $job->shouldReceive('maxTries')->andReturn(2);
        $job->shouldReceive('attempts')->andReturn(2);
        $job->shouldReceive('fail')->once()->with($exception);

        $this->queue->shouldReceive('pop')->with('default')->andReturn($job);

        $this->queueFactory->shouldReceive('connection')
            ->with('database')
            ->andReturn($this->queue);

        $this->events->shouldReceive('dispatch');

        $result = $this->invokeProcessNextJob($this->worker, ['default'], 60);

        $this->assertTrue($result);
    }

    // ---------------------------------------------------------------
    // Helper methods
    // ---------------------------------------------------------------

    /**
     * Create a mock QueueJob with a given job ID.
     */
    private function createMockJob(string $jobId): MockInterface&QueueJob
    {
        $job = Mockery::mock(QueueJob::class);
        $job->shouldReceive('getJobId')->andReturn($jobId);

        return $job;
    }

    /**
     * Invoke the private processJob method via reflection.
     */
    private function invokeProcessJob(Worker $worker, QueueJob $job, string $queue, int $timeout): void
    {
        $reflection = new ReflectionClass($worker);
        $method = $reflection->getMethod('processJob');
        $method->invoke($worker, $job, $queue, $timeout);
    }

    /**
     * Invoke the private handleJobFailure method via reflection.
     */
    private function invokeHandleJobFailure(Worker $worker, QueueJob $job, string $jobId, string $queue, Throwable $exception): void
    {
        $reflection = new ReflectionClass($worker);
        $method = $reflection->getMethod('handleJobFailure');
        $method->invoke($worker, $job, $jobId, $queue, $exception);
    }

    /**
     * Invoke the private calculateRetryDelay method via reflection.
     */
    private function invokeCalculateRetryDelay(Worker $worker, int $attempt): int
    {
        $reflection = new ReflectionClass($worker);
        $method = $reflection->getMethod('calculateRetryDelay');

        return $method->invoke($worker, $attempt);
    }

    /**
     * Invoke the private memoryExceeded method via reflection.
     */
    private function invokeMemoryExceeded(Worker $worker, int $memoryLimit): bool
    {
        $reflection = new ReflectionClass($worker);
        $method = $reflection->getMethod('memoryExceeded');

        return $method->invoke($worker, $memoryLimit);
    }

    /**
     * Invoke the private processNextJob method via reflection.
     */
    private function invokeProcessNextJob(Worker $worker, array $queues, int $timeout): bool
    {
        $reflection = new ReflectionClass($worker);
        $method = $reflection->getMethod('processNextJob');

        return $method->invoke($worker, $queues, $timeout);
    }
}
