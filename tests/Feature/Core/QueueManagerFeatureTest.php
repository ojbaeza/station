<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Core;

use Illuminate\Contracts\Queue\Queue;
use Mockery;
use Orchestra\Testbench\TestCase;
use Station\Contracts\DriverInterface;
use Station\Core\QueueManager;
use Station\StationServiceProvider;

class QueueManagerFeatureTest extends TestCase
{
    private QueueManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testPauseCallsDriverAndUpdatesDatabase(): void
    {
        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('pause')->with('emails')->once();

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);
        $laravelManager->shouldReceive('connection')->with('rabbitmq')->andReturn($driver);

        $manager = new QueueManager($laravelManager);
        $manager->pause('emails', 'rabbitmq');

        $this->assertDatabaseHas('station_queue_status', [
            'queue' => 'emails',
            'connection' => 'rabbitmq',
            'paused' => 1,
        ]);
    }

    public function testPauseWithNonDriverInterfaceOnlyUpdatesDatabase(): void
    {
        $driver = Mockery::mock(Queue::class);
        // Should NOT receive pause because it's not DriverInterface

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);
        $laravelManager->shouldReceive('connection')->with('test')->andReturn($driver);

        $manager = new QueueManager($laravelManager);
        $manager->pause('emails', 'test');

        $this->assertDatabaseHas('station_queue_status', [
            'queue' => 'emails',
            'connection' => 'test',
            'paused' => 1,
        ]);
    }

    public function testResumeCallsDriverAndUpdatesDatabase(): void
    {
        // First pause
        $this->app['db']->table('station_queue_status')->insert([
            'queue' => 'emails',
            'connection' => 'rabbitmq',
            'paused' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('resume')->with('emails')->once();

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);
        $laravelManager->shouldReceive('connection')->with('rabbitmq')->andReturn($driver);

        $manager = new QueueManager($laravelManager);
        $manager->resume('emails', 'rabbitmq');

        $this->assertDatabaseHas('station_queue_status', [
            'queue' => 'emails',
            'connection' => 'rabbitmq',
            'paused' => 0,
        ]);
    }

    public function testResumeWithNonDriverInterfaceOnlyUpdatesDatabase(): void
    {
        $this->app['db']->table('station_queue_status')->insert([
            'queue' => 'emails',
            'connection' => 'test',
            'paused' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $driver = Mockery::mock(Queue::class);

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);
        $laravelManager->shouldReceive('connection')->with('test')->andReturn($driver);

        $manager = new QueueManager($laravelManager);
        $manager->resume('emails', 'test');

        $this->assertDatabaseHas('station_queue_status', [
            'queue' => 'emails',
            'connection' => 'test',
            'paused' => 0,
        ]);
    }

    public function testIsPausedFallsBackToDatabaseForNonDriverInterface(): void
    {
        $this->app['db']->table('station_queue_status')->insert([
            'queue' => 'emails',
            'connection' => 'sync',
            'paused' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $driver = Mockery::mock(Queue::class);

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);
        $laravelManager->shouldReceive('connection')->with('sync')->andReturn($driver);

        $this->app['config']->set('station.default', 'sync');

        $manager = new QueueManager($laravelManager);

        $this->assertTrue($manager->isPaused('emails', 'sync'));
    }

    public function testIsPausedReturnsFalseFromDatabaseWhenNotPaused(): void
    {
        $this->app['db']->table('station_queue_status')->insert([
            'queue' => 'emails',
            'connection' => 'sync',
            'paused' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $driver = Mockery::mock(Queue::class);

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);
        $laravelManager->shouldReceive('connection')->with('sync')->andReturn($driver);

        $manager = new QueueManager($laravelManager);

        $this->assertFalse($manager->isPaused('emails', 'sync'));
    }

    public function testStatusReturnsQueueStatuses(): void
    {
        $this->app['db']->table('station_queue_status')->insert([
            [
                'queue' => 'default',
                'connection' => 'redis',
                'paused' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'queue' => 'emails',
                'connection' => 'redis',
                'paused' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $driver = Mockery::mock(Queue::class);
        $driver->shouldReceive('size')->with('default')->andReturn(5);
        $driver->shouldReceive('size')->with('emails')->andReturn(10);

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);
        $laravelManager->shouldReceive('connection')->with('redis')->andReturn($driver);

        $this->app['config']->set('station.default', 'redis');

        $manager = new QueueManager($laravelManager);
        $result = $manager->status('redis');

        $this->assertCount(2, $result);
        $this->assertSame(5, $result['default']['size']);
        $this->assertFalse($result['default']['paused']);
        $this->assertSame(10, $result['emails']['size']);
        $this->assertTrue($result['emails']['paused']);
    }

    public function testStatusUsesDefaultConnection(): void
    {
        $this->app['config']->set('station.default', 'redis');

        $this->app['db']->table('station_queue_status')->insert([
            'queue' => 'default',
            'connection' => 'redis',
            'paused' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $driver = Mockery::mock(Queue::class);
        $driver->shouldReceive('size')->with('default')->andReturn(3);

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);
        $laravelManager->shouldReceive('connection')->with('redis')->andReturn($driver);

        $manager = new QueueManager($laravelManager);
        $result = $manager->status();

        $this->assertCount(1, $result);
        $this->assertSame(3, $result['default']['size']);
    }

    public function testGetAllReturnsQueueNames(): void
    {
        $this->app['db']->table('station_queue_status')->insert([
            [
                'queue' => 'default',
                'connection' => 'redis',
                'paused' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'queue' => 'emails',
                'connection' => 'redis',
                'paused' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'queue' => 'other',
                'connection' => 'rabbitmq',
                'paused' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->app['config']->set('station.default', 'redis');

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);

        $manager = new QueueManager($laravelManager);
        $result = $manager->getAll('redis');

        $this->assertSame(['default', 'emails'], $result);
    }

    public function testGetAllUsesDefaultConnection(): void
    {
        $this->app['config']->set('station.default', 'rabbitmq');

        $this->app['db']->table('station_queue_status')->insert([
            'queue' => 'notifications',
            'connection' => 'rabbitmq',
            'paused' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);

        $manager = new QueueManager($laravelManager);
        $result = $manager->getAll();

        $this->assertSame(['notifications'], $result);
    }

    public function testPauseUpdatesExistingRowInsteadOfInserting(): void
    {
        $this->app['db']->table('station_queue_status')->insert([
            'queue' => 'emails',
            'connection' => 'rabbitmq',
            'paused' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('pause')->with('emails')->once();

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);
        $laravelManager->shouldReceive('connection')->with('rabbitmq')->andReturn($driver);

        $manager = new QueueManager($laravelManager);
        $manager->pause('emails', 'rabbitmq');

        // Should still be one row, updated
        $count = $this->app['db']->table('station_queue_status')
            ->where('queue', 'emails')
            ->where('connection', 'rabbitmq')
            ->count();
        $this->assertSame(1, $count);

        $this->assertDatabaseHas('station_queue_status', [
            'queue' => 'emails',
            'connection' => 'rabbitmq',
            'paused' => 1,
        ]);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
        $app['config']->set('station.default', 'redis');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    private function createTables(): void
    {
        $db = $this->app['db'];

        $db->statement('CREATE TABLE IF NOT EXISTS station_queue_status (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue VARCHAR(255) NOT NULL,
            connection VARCHAR(50) NULL,
            paused INTEGER NOT NULL DEFAULT 0,
            paused_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }
}
