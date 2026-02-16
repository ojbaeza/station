<?php

declare(strict_types=1);

namespace Station\Tests\Integration;

use Illuminate\Container\Container;
use Orchestra\Testbench\TestCase;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Job;
use Pheanstalk\Values\TubeName;
use PHPUnit\Framework\Attributes\Group;
use Station\Drivers\Beanstalkd\BeanstalkdConnection;
use Station\Drivers\Beanstalkd\BeanstalkdJob;
use Station\Drivers\Beanstalkd\BeanstalkdQueue;
use Station\StationServiceProvider;
use Throwable;

/**
 * Integration tests for BeanstalkdQueue.
 *
 * These tests require the Beanstalkd Docker container to be running:
 * docker compose up -d station_beanstalkd
 */
#[Group('integration')]
#[Group('beanstalkd')]
class BeanstalkdQueueIntegrationTest extends TestCase
{
    private BeanstalkdConnection $connection;

    private BeanstalkdQueue $queue;

    private string $testTube;

    /** @var array<string> */
    private array $tubesToClean = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->isBeanstalkdAvailable()) {
            $this->markTestSkipped('Beanstalkd is not available');
        }

        // Use a unique tube name for each test to avoid interference
        $this->testTube = 'station-test-' . uniqid();
        $this->tubesToClean[] = $this->testTube;

        $this->connection = new BeanstalkdConnection([
            'host' => 'station_beanstalkd',
            'port' => 11300,
            'timeout' => 10,
            'ttr' => 60,
            'reserve_timeout' => 1,
            'priority' => 1024,
        ]);

        $this->queue = new BeanstalkdQueue(
            $this->connection,
            $this->testTube,
            [],
        );

        $this->queue->setContainer($this->app);
    }

    protected function tearDown(): void
    {
        // Clean up all tubes used in this test
        foreach ($this->tubesToClean as $tube) {
            $this->clearTubeCompletely($tube);
        }

        parent::tearDown();
    }

    public function testHealthCheckReturnsConnected(): void
    {
        $health = $this->queue->healthCheck();

        $this->assertTrue($health['connected']);
        $this->assertArrayHasKey('latency_ms', $health);
        $this->assertIsInt($health['latency_ms']);
        $this->assertGreaterThanOrEqual(0, $health['latency_ms']);
    }

    public function testSizeReturnsZeroForEmptyTube(): void
    {
        $size = $this->queue->size($this->testTube);

        $this->assertSame(0, $size);
    }

    public function testPushRawAndSize(): void
    {
        $payload = json_encode(['job' => 'TestJob', 'data' => ['foo' => 'bar']]);

        $jobId = $this->queue->pushRaw($payload, $this->testTube);

        $this->assertNotEmpty($jobId);
        $this->assertIsNumeric($jobId);

        $size = $this->queue->size($this->testTube);
        $this->assertSame(1, $size);
    }

    public function testPushRawMultipleMessages(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->queue->pushRaw(json_encode(['index' => $i]), $this->testTube);
        }

        $size = $this->queue->size($this->testTube);
        $this->assertSame(5, $size);
    }

    public function testClearRemovesAllMessages(): void
    {
        // Push some messages
        for ($i = 0; $i < 3; $i++) {
            $this->queue->pushRaw(json_encode(['index' => $i]), $this->testTube);
        }

        // Verify they're there
        $this->assertSame(3, $this->queue->size($this->testTube));

        // Clear the tube
        $cleared = $this->queue->clear($this->testTube);

        $this->assertSame(3, $cleared);
        $this->assertSame(0, $this->queue->size($this->testTube));
    }

    public function testPopReturnsNullOnEmptyTube(): void
    {
        $job = $this->queue->pop($this->testTube);

        $this->assertNull($job);
    }

    public function testGetConnectionName(): void
    {
        $this->assertSame('station', $this->queue->getConnectionName());
    }

    public function testSetConnectionName(): void
    {
        $result = $this->queue->setConnectionName('custom');

        $this->assertSame($this->queue, $result);
        $this->assertSame('custom', $this->queue->getConnectionName());
    }

    public function testDefaultTubeIsUsedWhenNullPassed(): void
    {
        // Push to default tube (null)
        $this->queue->pushRaw(json_encode(['test' => true]), null);

        // Size should work with null (uses default)
        $size = $this->queue->size(null);
        $this->assertSame(1, $size);
    }

    public function testMultipleTubesAreIndependent(): void
    {
        $tube1 = 'station-test-' . uniqid() . '-1';
        $tube2 = 'station-test-' . uniqid() . '-2';
        $this->tubesToClean[] = $tube1;
        $this->tubesToClean[] = $tube2;

        // Push to different tubes
        $this->queue->pushRaw(json_encode(['tube' => 1]), $tube1);
        $this->queue->pushRaw(json_encode(['tube' => 2]), $tube2);
        $this->queue->pushRaw(json_encode(['tube' => 2]), $tube2);

        $this->assertSame(1, $this->queue->size($tube1));
        $this->assertSame(2, $this->queue->size($tube2));
    }

    public function testPopReturnsBeanstalkdJobWhenMessageExists(): void
    {
        $payload = ['job' => 'TestJob', 'data' => ['key' => 'value']];
        $this->queue->pushRaw(json_encode($payload), $this->testTube);

        $job = $this->queue->pop($this->testTube);

        $this->assertInstanceOf(BeanstalkdJob::class, $job);
    }

    public function testJobGetRawBodyReturnsPayload(): void
    {
        $payload = ['job' => 'TestJob', 'data' => ['key' => 'value']];
        $this->queue->pushRaw(json_encode($payload), $this->testTube);

        $job = $this->queue->pop($this->testTube);

        $this->assertSame(json_encode($payload), $job->getRawBody());
    }

    public function testJobPayloadReturnsDecodedPayload(): void
    {
        $payload = ['job' => 'TestJob', 'data' => ['key' => 'value']];
        $this->queue->pushRaw(json_encode($payload), $this->testTube);

        $job = $this->queue->pop($this->testTube);

        $this->assertSame($payload, $job->payload());
    }

    public function testJobGetQueueReturnsTubeName(): void
    {
        $this->queue->pushRaw(json_encode(['test' => true]), $this->testTube);

        $job = $this->queue->pop($this->testTube);

        $this->assertSame($this->testTube, $job->getQueue());
    }

    public function testJobGetPheanstalkJobReturnsJob(): void
    {
        $this->queue->pushRaw(json_encode(['test' => true]), $this->testTube);

        $job = $this->queue->pop($this->testTube);

        $pheanstalkJob = $job->getPheanstalkJob();
        $this->assertInstanceOf(Job::class, $pheanstalkJob);
    }

    public function testJobAttemptsReturnsDefaultOne(): void
    {
        $this->queue->pushRaw(json_encode(['test' => true]), $this->testTube);

        $job = $this->queue->pop($this->testTube);

        $this->assertSame(1, $job->attempts());
    }

    public function testJobAttemptsReturnsPayloadAttempts(): void
    {
        $payload = ['test' => true, 'attempts' => 3];
        $this->queue->pushRaw(json_encode($payload), $this->testTube);

        $job = $this->queue->pop($this->testTube);

        $this->assertSame(3, $job->attempts());
    }

    public function testJobGetJobIdReturnsId(): void
    {
        $this->queue->pushRaw(json_encode(['test' => true]), $this->testTube);

        $job = $this->queue->pop($this->testTube);

        $jobId = $job->getJobId();
        $this->assertNotEmpty($jobId);
        $this->assertIsNumeric($jobId);
    }

    public function testJobGetJobIdReturnsPayloadUuid(): void
    {
        $payload = ['test' => true, 'uuid' => 'custom-uuid-12345'];
        $this->queue->pushRaw(json_encode($payload), $this->testTube);

        $job = $this->queue->pop($this->testTube);

        $this->assertSame('custom-uuid-12345', $job->getJobId());
    }

    public function testJobDeleteRemovesJob(): void
    {
        $this->queue->pushRaw(json_encode(['test' => true]), $this->testTube);
        $this->assertSame(1, $this->queue->size($this->testTube));

        $job = $this->queue->pop($this->testTube);
        $job->delete();

        // After delete, size should be 0 (message was removed)
        $this->assertSame(0, $this->queue->size($this->testTube));
    }

    public function testJobReleaseRequeuesMessage(): void
    {
        $this->queue->pushRaw(json_encode(['test' => true]), $this->testTube);

        $job = $this->queue->pop($this->testTube);
        // While job is reserved, size doesn't include it in ready count
        // Release puts it back
        $job->release(0);

        // Job should be back in the queue
        $this->assertSame(1, $this->queue->size($this->testTube));
    }

    public function testJobGetStatsReturnsArray(): void
    {
        $this->queue->pushRaw(json_encode(['test' => true]), $this->testTube);

        $job = $this->queue->pop($this->testTube);
        $stats = $job->getStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('pri', $stats);
        $this->assertArrayHasKey('reserves', $stats);
        $this->assertArrayHasKey('ttr', $stats);
    }

    public function testJobGetPriorityReturnsDefaultPriority(): void
    {
        $this->queue->pushRaw(json_encode(['test' => true]), $this->testTube);

        $job = $this->queue->pop($this->testTube);
        $priority = $job->getPriority();

        $this->assertSame(1024, $priority);
    }

    public function testJobTouchResetsTimeLeft(): void
    {
        $this->queue->pushRaw(json_encode(['test' => true]), $this->testTube);

        $job = $this->queue->pop($this->testTube);
        $initialTimeLeft = $job->getTimeLeft();

        // Touch resets the TTR
        $job->touch();
        $newTimeLeft = $job->getTimeLeft();

        // Time left should be reset (or at least not less than before)
        $this->assertGreaterThanOrEqual($initialTimeLeft, $newTimeLeft);
    }

    public function testConnectionIsConnected(): void
    {
        // First call getClient() to establish the connection
        $this->connection->getClient();
        $this->assertTrue($this->connection->isConnected());
    }

    public function testConnectionGetClientReturnsClient(): void
    {
        $client = $this->connection->getClient();

        $this->assertInstanceOf(Pheanstalk::class, $client);
    }

    public function testConnectionDisconnect(): void
    {
        // First call getClient() to establish the connection
        $this->connection->getClient();
        $this->assertTrue($this->connection->isConnected());

        $this->connection->disconnect();
        $this->assertFalse($this->connection->isConnected());
    }

    public function testConnectionGetDefaultTube(): void
    {
        $connection = new BeanstalkdConnection([
            'host' => 'station_beanstalkd',
            'port' => 11300,
            'queue' => 'custom-tube',
        ]);

        $this->assertSame('custom-tube', $connection->getDefaultTube());
    }

    public function testConnectionGetTtr(): void
    {
        $this->assertSame(60, $this->connection->getTtr());
    }

    public function testConnectionGetReserveTimeout(): void
    {
        $this->assertSame(1, $this->connection->getReserveTimeout());
    }

    public function testConnectionGetDefaultPriority(): void
    {
        $this->assertSame(1024, $this->connection->getDefaultPriority());
    }

    public function testConnectionGetRetryDelay(): void
    {
        $connection = new BeanstalkdConnection([
            'host' => 'station_beanstalkd',
            'port' => 11300,
            'retry_delay' => 120,
        ]);

        $this->assertSame(120, $connection->getRetryDelay());
    }

    public function testLaterRawCreatesDelayedJob(): void
    {
        $jobId = $this->queue->laterRaw(2, json_encode(['test' => true]), $this->testTube);

        $this->assertNotEmpty($jobId);
        $this->assertIsNumeric($jobId);

        // Job should be in delayed state, not ready
        // Size includes delayed jobs in Beanstalkd
        $this->assertSame(1, $this->queue->size($this->testTube));
    }

    public function testPushRawWithPriority(): void
    {
        // Push with high priority (lower number = higher priority)
        $jobId = $this->queue->pushRaw(
            json_encode(['test' => true]),
            $this->testTube,
            ['priority' => 100],
        );

        $job = $this->queue->pop($this->testTube);
        $this->assertSame(100, $job->getPriority());
    }

    public function testKickBuriedJobsReturnsCountWhenNoJobs(): void
    {
        $kicked = $this->queue->kickBuriedJobs($this->testTube, 5);

        $this->assertSame(0, $kicked);
    }

    public function testJobBuryAndKick(): void
    {
        $this->queue->pushRaw(json_encode(['test' => true]), $this->testTube);

        $job = $this->queue->pop($this->testTube);
        $job->bury();

        // Job should be buried (not in ready state)
        // Push another to verify
        $this->queue->pushRaw(json_encode(['test2' => true]), $this->testTube);
        $this->assertSame(1, $this->queue->size($this->testTube)); // Only the new one is ready

        // Kick the buried job
        $kicked = $this->queue->kickBuriedJobs($this->testTube, 1);
        $this->assertSame(1, $kicked);

        // Now should have 2 ready jobs
        $this->assertSame(2, $this->queue->size($this->testTube));
    }

    public function testGetDefaultPriorityFromQueue(): void
    {
        $this->assertSame(1024, $this->queue->getDefaultPriority());
    }

    public function testRequeueFromDeadLetterReturnsFalseForNonExistent(): void
    {
        $result = $this->queue->requeueFromDeadLetter($this->testTube, '999999');

        $this->assertFalse($result);
    }

    public function testGetDeadLetterQueueReturnsEmptyArray(): void
    {
        $messages = $this->queue->getDeadLetterQueue($this->testTube);

        $this->assertIsArray($messages);
        $this->assertEmpty($messages);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    /**
     * Completely drain a tube of all messages (ready, reserved, delayed, buried).
     */
    private function clearTubeCompletely(string $tubeName): void
    {
        try {
            $bean = Pheanstalk::create('station_beanstalkd', 11300);
            $tube = new TubeName($tubeName);
            $bean->useTube($tube);
            $bean->watch($tube);
            $bean->ignore(new TubeName('default'));

            // Drain ready jobs
            while (true) {
                try {
                    $job = $bean->peekReady();
                    if ($job === null) {
                        break;
                    }
                    $bean->delete($job);
                } catch (Throwable) {
                    break;
                }
            }

            // Drain delayed jobs
            while (true) {
                try {
                    $job = $bean->peekDelayed();
                    if ($job === null) {
                        break;
                    }
                    $bean->delete($job);
                } catch (Throwable) {
                    break;
                }
            }

            // Drain buried jobs
            while (true) {
                try {
                    $job = $bean->peekBuried();
                    if ($job === null) {
                        break;
                    }
                    $bean->delete($job);
                } catch (Throwable) {
                    break;
                }
            }

            // Drain any reserved jobs by reserving and deleting
            for ($i = 0; $i < 10; $i++) {
                try {
                    $job = $bean->reserveWithTimeout(0);
                    if ($job === null) {
                        break;
                    }
                    $bean->delete($job);
                } catch (Throwable) {
                    break;
                }
            }
        } catch (Throwable) {
            // Ignore errors during cleanup
        }
    }

    private function isBeanstalkdAvailable(): bool
    {
        try {
            $bean = Pheanstalk::create('station_beanstalkd', 11300);
            $tube = new TubeName('test-availability');
            $bean->useTube($tube);
            $job = $bean->put('ping');
            $bean->delete($job);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
