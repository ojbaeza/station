<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Alerts\Evaluators;

use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Alerts\Evaluators\WorkerDownEvaluator;
use Station\Contracts\WorkerRepositoryInterface;
use Station\DTOs\AlertRule;
use Station\Enums\AlertSeverity;
use Station\Enums\AlertType;

class WorkerDownEvaluatorTest extends TestCase
{
    private MockInterface&WorkerRepositoryInterface $workerRepository;

    private WorkerDownEvaluator $sut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workerRepository = Mockery::mock(WorkerRepositoryInterface::class);
        $this->sut = new WorkerDownEvaluator($this->workerRepository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testEvaluateReturnsNullWhenActiveWorkersAtMinimum(): void
    {
        $rule = $this->makeRule(['min_workers' => 2]);

        $this->workerRepository
            ->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([
                ['id' => 'w-1', 'status' => 'active'],
                ['id' => 'w-2', 'status' => 'active'],
            ]));

        $result = $this->sut->evaluate($rule);

        $this->assertNull($result);
    }

    public function testEvaluateReturnsNullWhenActiveWorkersAboveMinimum(): void
    {
        $rule = $this->makeRule(['min_workers' => 2]);

        $this->workerRepository
            ->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([
                ['id' => 'w-1', 'status' => 'active'],
                ['id' => 'w-2', 'status' => 'active'],
                ['id' => 'w-3', 'status' => 'active'],
            ]));

        $result = $this->sut->evaluate($rule);

        $this->assertNull($result);
    }

    public function testEvaluateReturnsWarningWhenActiveBelowMinimumButAboveZero(): void
    {
        $rule = $this->makeRule(['min_workers' => 3]);

        $this->workerRepository
            ->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([
                ['id' => 'w-1', 'status' => 'active'],
            ]));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertSame(AlertSeverity::Warning, $result->severity);
    }

    public function testEvaluateReturnsCriticalWhenActiveWorkersIsZero(): void
    {
        $rule = $this->makeRule(['min_workers' => 1]);

        $this->workerRepository
            ->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([]));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertSame(AlertSeverity::Critical, $result->severity);
    }

    public function testEvaluateUsesDefaultMinWorkersOf1WhenConditionEmpty(): void
    {
        $rule = $this->makeRule([]);

        $this->workerRepository
            ->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([
                ['id' => 'w-1', 'status' => 'active'],
            ]));

        // 1 active >= 1 min_workers (default) => null
        $result = $this->sut->evaluate($rule);

        $this->assertNull($result);
    }

    public function testEvaluateUsesDefaultMinWorkersOf1TriggersWhenZeroWorkers(): void
    {
        $rule = $this->makeRule([]);

        $this->workerRepository
            ->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([]));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertSame(AlertSeverity::Critical, $result->severity);
    }

    public function testEvaluateContextIncludesActiveWorkersAndMinWorkers(): void
    {
        $rule = $this->makeRule(['min_workers' => 5]);

        $this->workerRepository
            ->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([
                ['id' => 'w-1', 'status' => 'active'],
                ['id' => 'w-2', 'status' => 'active'],
            ]));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('active_workers', $result->context);
        $this->assertArrayHasKey('min_workers', $result->context);
        $this->assertSame(2, $result->context['active_workers']);
        $this->assertSame(5, $result->context['min_workers']);
    }

    public function testEvaluateMessageIncludesCountAndMinimum(): void
    {
        $rule = $this->makeRule(['min_workers' => 4]);

        $this->workerRepository
            ->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([
                ['id' => 'w-1', 'status' => 'active'],
            ]));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertStringContainsString('1 active worker(s)', $result->message);
        $this->assertStringContainsString('minimum: 4', $result->message);
    }

    public function testEvaluateMessageForZeroWorkers(): void
    {
        $rule = $this->makeRule(['min_workers' => 2]);

        $this->workerRepository
            ->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([]));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertStringContainsString('0 active worker(s)', $result->message);
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function makeRule(array $condition): AlertRule
    {
        return new AlertRule(
            id: 'rule-wd-1',
            name: 'Worker Down',
            type: AlertType::WorkerDown,
            enabled: true,
            condition: $condition,
            window: 300,
            channel_ids: ['ch-1'],
            cooldown: 300,
        );
    }
}
