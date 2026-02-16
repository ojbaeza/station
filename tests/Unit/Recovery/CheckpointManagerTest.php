<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Recovery;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Contracts\CheckpointRepositoryInterface;
use Station\Recovery\CheckpointManager;

class CheckpointManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface&CheckpointRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Mockery::mock(CheckpointRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testIsEnabledReturnsTrueByDefault(): void
    {
        $manager = new CheckpointManager($this->repository, []);

        $this->assertTrue($manager->isEnabled());
    }

    public function testIsEnabledRespectsConfig(): void
    {
        $manager = new CheckpointManager($this->repository, ['enabled' => false]);

        $this->assertFalse($manager->isEnabled());
    }

    public function testSaveDelegatesToRepository(): void
    {
        $data = ['step' => 5, 'processed' => 100];

        $this->repository->shouldReceive('save')
            ->once()
            ->with('job-1', $data);

        $manager = new CheckpointManager($this->repository, ['enabled' => true]);

        $manager->save('job-1', $data);
    }

    public function testSaveDoesNothingWhenDisabled(): void
    {
        $this->repository->shouldNotReceive('save');

        $manager = new CheckpointManager($this->repository, ['enabled' => false]);

        $manager->save('job-1', ['step' => 5]);
    }

    public function testGetDelegatesToRepository(): void
    {
        $data = ['step' => 5, 'processed' => 100];

        $this->repository->shouldReceive('get')
            ->once()
            ->with('job-1')
            ->andReturn($data);

        $manager = new CheckpointManager($this->repository, ['enabled' => true]);

        $result = $manager->get('job-1');

        $this->assertSame($data, $result);
    }

    public function testGetReturnsNullWhenDisabled(): void
    {
        $this->repository->shouldNotReceive('get');

        $manager = new CheckpointManager($this->repository, ['enabled' => false]);

        $result = $manager->get('job-1');

        $this->assertNull($result);
    }

    public function testDeleteDelegatesToRepository(): void
    {
        $this->repository->shouldReceive('delete')
            ->once()
            ->with('job-1');

        $manager = new CheckpointManager($this->repository, []);

        $manager->delete('job-1');
    }

    public function testExistsDelegatesToRepository(): void
    {
        $this->repository->shouldReceive('exists')
            ->once()
            ->with('job-1')
            ->andReturn(true);

        $manager = new CheckpointManager($this->repository, []);

        $result = $manager->exists('job-1');

        $this->assertTrue($result);
    }

    public function testPruneDelegatesToRepository(): void
    {
        $this->repository->shouldReceive('prune')
            ->once()
            ->with(24)
            ->andReturn(10);

        $manager = new CheckpointManager($this->repository, []);

        $result = $manager->prune();

        $this->assertSame(10, $result);
    }

    public function testPruneUsesCustomRetention(): void
    {
        $this->repository->shouldReceive('prune')
            ->once()
            ->with(48)
            ->andReturn(5);

        $manager = new CheckpointManager($this->repository, ['retention' => 48]);

        $result = $manager->prune();

        $this->assertSame(5, $result);
    }
}
