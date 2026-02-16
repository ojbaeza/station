<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Repositories;

use Carbon\CarbonImmutable;
use Orchestra\Testbench\TestCase;
use Station\Repositories\DatabaseCheckpointRepository;
use Station\StationServiceProvider;

class DatabaseCheckpointRepositoryTest extends TestCase
{
    private DatabaseCheckpointRepository $repository;

    private DatabaseCheckpointRepository $encryptedRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');

        // Non-encrypted repository
        $this->repository = new DatabaseCheckpointRepository(
            $this->app['db']->connection(),
            'station_checkpoints',
            false,
            $this->app['encrypter'],
        );

        // Encrypted repository
        $this->encryptedRepository = new DatabaseCheckpointRepository(
            $this->app['db']->connection(),
            'station_checkpoints',
            true,
            $this->app['encrypter'],
        );
    }

    public function testSaveCreatesCheckpoint(): void
    {
        $this->repository->save('job-1', ['progress' => 50, 'items' => ['a', 'b']]);

        $this->assertDatabaseHas('station_checkpoints', [
            'job_id' => 'job-1',
            'encrypted' => false,
        ]);
    }

    public function testSaveWithEncryption(): void
    {
        $this->encryptedRepository->save('job-1', ['progress' => 50]);

        $this->assertDatabaseHas('station_checkpoints', [
            'job_id' => 'job-1',
            'encrypted' => true,
        ]);

        // Data should be encrypted
        $row = $this->app['db']->table('station_checkpoints')
            ->where('job_id', 'job-1')
            ->first();

        // The data should not be plain JSON
        $decoded = json_decode($row->data, true);
        $this->assertNull($decoded); // Should fail to decode encrypted data
    }

    public function testGetReturnsCheckpointData(): void
    {
        $this->repository->save('job-1', ['progress' => 75, 'last_item' => 'xyz']);

        $result = $this->repository->get('job-1');

        $this->assertNotNull($result);
        $this->assertSame(75, $result['progress']);
        $this->assertSame('xyz', $result['last_item']);
    }

    public function testGetReturnsNullForNonexistentCheckpoint(): void
    {
        $result = $this->repository->get('nonexistent');

        $this->assertNull($result);
    }

    public function testGetDecryptsEncryptedData(): void
    {
        $this->encryptedRepository->save('job-1', ['secret' => 'value']);

        $result = $this->encryptedRepository->get('job-1');

        $this->assertNotNull($result);
        $this->assertSame('value', $result['secret']);
    }

    public function testDeleteRemovesCheckpoint(): void
    {
        $this->repository->save('job-1', ['data' => 'test']);

        $this->repository->delete('job-1');

        $this->assertDatabaseMissing('station_checkpoints', [
            'job_id' => 'job-1',
        ]);
    }

    public function testExistsReturnsTrueForExistingCheckpoint(): void
    {
        $this->repository->save('job-1', ['data' => 'test']);

        $result = $this->repository->exists('job-1');

        $this->assertTrue($result);
    }

    public function testExistsReturnsFalseForNonexistentCheckpoint(): void
    {
        $result = $this->repository->exists('nonexistent');

        $this->assertFalse($result);
    }

    public function testPruneRemovesOldCheckpoints(): void
    {
        // Create an old checkpoint
        $this->app['db']->table('station_checkpoints')->insert([
            'job_id' => 'old-job',
            'data' => json_encode(['old' => 'data']),
            'encrypted' => false,
            'created_at' => CarbonImmutable::now()->subHours(48)->toDateTimeString(),
            'updated_at' => CarbonImmutable::now()->subHours(48)->toDateTimeString(),
        ]);

        // Create a recent checkpoint
        $this->repository->save('new-job', ['new' => 'data']);

        $deleted = $this->repository->prune(24);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('station_checkpoints', ['job_id' => 'old-job']);
        $this->assertDatabaseHas('station_checkpoints', ['job_id' => 'new-job']);
    }

    public function testSaveUpdatesExistingCheckpoint(): void
    {
        $this->repository->save('job-1', ['progress' => 25]);
        $this->repository->save('job-1', ['progress' => 75]);

        $result = $this->repository->get('job-1');

        $this->assertSame(75, $result['progress']);

        // Should only have one record
        $count = $this->app['db']->table('station_checkpoints')
            ->where('job_id', 'job-1')
            ->count();

        $this->assertSame(1, $count);
    }

    public function testSavePreservesComplexDataStructures(): void
    {
        $complexData = [
            'progress' => 50,
            'processed_items' => [1, 2, 3, 4, 5],
            'metadata' => [
                'started_at' => '2025-01-27 10:00:00',
                'batch_size' => 100,
            ],
            'errors' => [],
        ];

        $this->repository->save('job-1', $complexData);

        $result = $this->repository->get('job-1');

        $this->assertSame($complexData, $result);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }
}
