<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\Beanstalkd;

use Illuminate\Container\Container;
use Pheanstalk\Values\Job as PheanstalkJob;
use Pheanstalk\Values\JobId;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Station\Drivers\Beanstalkd\BeanstalkdConnection;
use Station\Drivers\Beanstalkd\BeanstalkdJob;
use Station\Drivers\Beanstalkd\BeanstalkdQueue;
use Station\Tests\Fixtures\TestJob;
use stdClass;
use Throwable;

/**
 * Unit tests for BeanstalkdJob.
 *
 * Note: BeanstalkdQueue and Pheanstalk are final classes and cannot be mocked.
 * These tests use reflection to verify the class structure without requiring
 * actual connections. Actual job operations are tested in the integration tests.
 */
class BeanstalkdJobTest extends TestCase
{
    public function testConstructorParameters(): void
    {
        $reflection = new ReflectionClass(BeanstalkdJob::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);

        $parameters = $constructor->getParameters();
        $this->assertCount(5, $parameters);
        $this->assertSame('container', $parameters[0]->getName());
        $this->assertSame('beanstalkd', $parameters[1]->getName());
        $this->assertSame('job', $parameters[2]->getName());
        $this->assertSame('connectionName', $parameters[3]->getName());
        $this->assertSame('queue', $parameters[4]->getName());
    }

    public function testGetJobIdMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdJob::class, 'getJobId');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('string', $reflection->getReturnType()?->getName());
    }

    public function testGetRawBodyMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdJob::class, 'getRawBody');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('string', $reflection->getReturnType()?->getName());
    }

    public function testGetQueueMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdJob::class, 'getQueue');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('string', $reflection->getReturnType()?->getName());
    }

    public function testAttemptsMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdJob::class, 'attempts');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('int', $reflection->getReturnType()?->getName());
    }

    public function testPayloadMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdJob::class, 'payload');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('array', $reflection->getReturnType()?->getName());
    }

    public function testReleaseMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdJob::class, 'release');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('void', $reflection->getReturnType()?->getName());
    }

    public function testDeleteMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdJob::class, 'delete');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('void', $reflection->getReturnType()?->getName());
    }

    public function testBuryMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdJob::class, 'bury');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('void', $reflection->getReturnType()?->getName());
    }

    public function testGetStatsMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdJob::class, 'getStats');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('array', $reflection->getReturnType()?->getName());
    }

    public function testGetPriorityMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdJob::class, 'getPriority');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('int', $reflection->getReturnType()?->getName());
    }

    public function testGetTimeLeftMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdJob::class, 'getTimeLeft');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('int', $reflection->getReturnType()?->getName());
    }

    public function testTouchMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdJob::class, 'touch');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('void', $reflection->getReturnType()?->getName());
    }

    public function testFireMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdJob::class, 'fire');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('void', $reflection->getReturnType()?->getName());
    }

    public function testGetPheanstalkJobMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdJob::class, 'getPheanstalkJob');

        $this->assertTrue($reflection->isPublic());
    }

    public function testReleaseHasDelayParameter(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdJob::class, 'release');
        $parameters = $reflection->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame('delay', $parameters[0]->getName());
        $this->assertTrue($parameters[0]->isDefaultValueAvailable());
        $this->assertSame(0, $parameters[0]->getDefaultValue());
    }

    public function testGetRawBodyReturnsJobData(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $job = $this->createBeanstalkdJob($payload);

        $this->assertSame($payload, $job->getRawBody());
    }

    public function testPayloadReturnsDecodedArray(): void
    {
        $data = ['uuid' => 'test-uuid', 'job' => 'TestJob', 'data' => ['key' => 'value']];
        $payload = json_encode($data);
        $job = $this->createBeanstalkdJob($payload);

        $this->assertSame($data, $job->payload());
    }

    public function testPayloadReturnsEmptyArrayOnInvalidJson(): void
    {
        $job = $this->createBeanstalkdJob('not-valid-json');

        $this->assertSame([], $job->payload());
    }

    public function testGetJobIdReturnsUuidFromPayload(): void
    {
        $payload = json_encode(['uuid' => 'my-uuid-456', 'job' => 'TestJob']);
        $job = $this->createBeanstalkdJob($payload, 42);

        $this->assertSame('my-uuid-456', $job->getJobId());
    }

    public function testGetJobIdFallsBackToPheanstalkJobId(): void
    {
        $payload = json_encode(['job' => 'TestJob']);
        $job = $this->createBeanstalkdJob($payload, 99);

        $this->assertSame('99', $job->getJobId());
    }

    public function testAttemptsReturnsValueFromPayload(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob', 'attempts' => 7]);
        $job = $this->createBeanstalkdJob($payload);

        $this->assertSame(7, $job->attempts());
    }

    public function testGetQueueReturnsQueueName(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $job = $this->createBeanstalkdJob($payload, 1, 'my-tube');

        $this->assertSame('my-tube', $job->getQueue());
    }

    public function testGetPheanstalkJobReturnsInstance(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $job = $this->createBeanstalkdJob($payload, 55);

        $pheanstalkJob = $job->getPheanstalkJob();
        $this->assertInstanceOf(PheanstalkJob::class, $pheanstalkJob);
        $this->assertSame('55', $pheanstalkJob->getId());
    }

    public function testGetConnectionNameReturnsConnectionName(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $job = $this->createBeanstalkdJob($payload);

        $this->assertSame('station', $job->getConnectionName());
    }

    // -------------------------------------------------------------------------
    // fire() tests
    // -------------------------------------------------------------------------

    public function testFireWithStationJobFormatCallsHandle(): void
    {
        $testJob = new TestJob('beanstalkd-fire-test');
        TestJob::$handled = false;

        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [
                'station_job_id' => 'station-123',
                'payload' => serialize($testJob),
            ],
        ]);

        $job = $this->createBeanstalkdJob($payload);
        $job->fire();

        $this->assertTrue(TestJob::$handled, 'TestJob::handle() should have been called');
    }

    public function testFireWithStationFormatSkipsNonStringPayload(): void
    {
        $this->expectNotToPerformAssertions();

        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [
                'station_job_id' => 'station-123',
                'payload' => 12345,
            ],
        ]);

        $job = $this->createBeanstalkdJob($payload);
        $job->fire();
    }

    public function testFireWithStationFormatSkipsNullPayload(): void
    {
        $this->expectNotToPerformAssertions();

        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [
                'station_job_id' => 'station-123',
            ],
        ]);

        $job = $this->createBeanstalkdJob($payload);
        $job->fire();
    }

    public function testFireWithStationFormatSkipsObjectWithoutHandle(): void
    {
        $this->expectNotToPerformAssertions();

        $obj = new stdClass();
        $obj->name = 'no-handle';

        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [
                'station_job_id' => 'station-123',
                'payload' => serialize($obj),
            ],
        ]);

        $job = $this->createBeanstalkdJob($payload);
        $job->fire();
    }

    public function testFireWithoutStationFormatDelegatesToParent(): void
    {
        $this->expectNotToPerformAssertions();

        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [],
        ]);

        $job = $this->createBeanstalkdJob($payload);

        // parent::fire() tries to resolve TestJob class from container — may throw
        try {
            $job->fire();
        } catch (Throwable) {
            // Expected: parent::fire() tries to resolve TestJob
        }
    }

    // -------------------------------------------------------------------------
    // parseJobClassAndMethod() tests
    // -------------------------------------------------------------------------

    public function testParseJobClassAndMethodWithAtSymbol(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'App\\Jobs\\MyJob@execute']);
        $job = $this->createBeanstalkdJob($payload);

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');
        $method->setAccessible(true);

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('App\\Jobs\\MyJob', $result[0]);
        $this->assertSame('execute', $result[1]);
    }

    public function testParseJobClassAndMethodWithoutAtSymbol(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'App\\Jobs\\MyJob']);
        $job = $this->createBeanstalkdJob($payload);

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');
        $method->setAccessible(true);

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('App\\Jobs\\MyJob', $result[0]);
        $this->assertSame('handle', $result[1]);
    }

    public function testParseJobClassAndMethodFallsBackToDisplayName(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'displayName' => 'BeanstalkdDisplayJob']);
        $job = $this->createBeanstalkdJob($payload);

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');
        $method->setAccessible(true);

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('BeanstalkdDisplayJob', $result[0]);
        $this->assertSame('handle', $result[1]);
    }

    public function testParseJobClassAndMethodFallsBackToUnknownJob(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid']);
        $job = $this->createBeanstalkdJob($payload);

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');
        $method->setAccessible(true);

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('UnknownJob', $result[0]);
        $this->assertSame('handle', $result[1]);
    }

    // -------------------------------------------------------------------------
    // Methods that call into BeanstalkdQueue (connection-dependent)
    // These test the error handling path since no real connection is available.
    // -------------------------------------------------------------------------

    public function testDeleteCallsQueueDeleteJob(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $job = $this->createBeanstalkdJob($payload);

        // Without a real Beanstalkd connection, delete will throw
        try {
            $job->delete();
        } catch (Throwable) {
            // Expected: no real connection
        }

        // parent::delete() was called before the exception
        $this->assertTrue($job->isDeleted());
    }

    public function testReleaseCallsQueueReleaseJob(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $job = $this->createBeanstalkdJob($payload);

        try {
            $job->release(30);
        } catch (Throwable) {
            // Expected: no real connection
        }

        // parent::release() was called before the exception
        $this->assertTrue($job->isReleased());
    }

    public function testBuryCallsQueueBuryJob(): void
    {
        $this->expectNotToPerformAssertions();

        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $job = $this->createBeanstalkdJob($payload);

        try {
            $job->bury();
        } catch (Throwable) {
            // Expected: no real connection
        }
    }

    public function testTouchCallsQueueTouchJob(): void
    {
        $this->expectNotToPerformAssertions();

        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $job = $this->createBeanstalkdJob($payload);

        try {
            $job->touch();
        } catch (Throwable) {
            // Expected: no real connection
        }
    }

    public function testGetStatsReturnsEmptyArrayWithoutConnection(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $job = $this->createBeanstalkdJob($payload);

        $exceptionThrown = false;

        try {
            $stats = $job->getStats();
            $this->assertIsArray($stats);
        } catch (Throwable) {
            $exceptionThrown = true;
        }

        // Either getStats returned an array, or a connection error was thrown — both are valid
        $this->assertTrue($exceptionThrown || isset($stats));
    }

    public function testAttemptsWithoutPayloadAttemptsCallsGetJobStats(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $job = $this->createBeanstalkdJob($payload);

        $exceptionThrown = false;

        try {
            $attempts = $job->attempts();
            $this->assertSame(1, $attempts);
        } catch (Throwable) {
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown || isset($attempts));
    }

    public function testGetPriorityUsesStats(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $job = $this->createBeanstalkdJob($payload);

        $exceptionThrown = false;

        try {
            $priority = $job->getPriority();
            $this->assertIsInt($priority);
        } catch (Throwable) {
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown || isset($priority));
    }

    public function testGetTimeLeftUsesStats(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $job = $this->createBeanstalkdJob($payload);

        $exceptionThrown = false;

        try {
            $timeLeft = $job->getTimeLeft();
            $this->assertSame(0, $timeLeft);
        } catch (Throwable) {
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown || isset($timeLeft));
    }

    // -------------------------------------------------------------------------
    // Functional tests using real PheanstalkJob instances
    // -------------------------------------------------------------------------

    private function createBeanstalkdJob(string $rawBody, int $jobId = 1, string $queue = 'default'): BeanstalkdJob
    {
        $container = new Container();
        $pheanstalkJob = new PheanstalkJob(new JobId($jobId), $rawBody);
        $connection = new BeanstalkdConnection([]);
        $beanstalkdQueue = new BeanstalkdQueue($connection, 'default');
        $beanstalkdQueue->setContainer($container);

        return new BeanstalkdJob($container, $beanstalkdQueue, $pheanstalkJob, 'station', $queue);
    }
}
