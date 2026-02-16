<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Workflows;

use PHPUnit\Framework\TestCase;
use Station\Workflows\WorkflowStep;
use stdClass;

class WorkflowStepTest extends TestCase
{
    public function testGetName(): void
    {
        $step = new WorkflowStep('step1', stdClass::class);

        $this->assertSame('step1', $step->getName());
    }

    public function testGetJobClass(): void
    {
        $step = new WorkflowStep('step1', stdClass::class);

        $this->assertSame(stdClass::class, $step->getJobClass());
    }

    public function testGetDependencies(): void
    {
        $step = new WorkflowStep('step2', stdClass::class, ['step1', 'step3']);

        $this->assertSame(['step1', 'step3'], $step->getDependencies());
    }

    public function testHasConditionReturnsFalseWithoutCondition(): void
    {
        $step = new WorkflowStep('step1', stdClass::class);

        $this->assertFalse($step->hasCondition());
    }

    public function testHasConditionReturnsTrueWithCondition(): void
    {
        $step = new WorkflowStep(
            'step1',
            stdClass::class,
            [],
            static fn($context) => true,
        );

        $this->assertTrue($step->hasCondition());
    }

    public function testShouldRunReturnsTrueWithoutCondition(): void
    {
        $step = new WorkflowStep('step1', stdClass::class);

        $this->assertTrue($step->shouldRun([]));
        $this->assertTrue($step->shouldRun(['any' => 'context']));
    }

    public function testShouldRunEvaluatesCondition(): void
    {
        $step = new WorkflowStep(
            'step1',
            stdClass::class,
            [],
            static fn($context) => $context['enabled'] ?? false,
        );

        $this->assertFalse($step->shouldRun([]));
        $this->assertFalse($step->shouldRun(['enabled' => false]));
        $this->assertTrue($step->shouldRun(['enabled' => true]));
    }

    public function testIsBranchReturnsFalseWithoutBranches(): void
    {
        $step = new WorkflowStep('step1', stdClass::class);

        $this->assertFalse($step->isBranch());
        $this->assertNull($step->getBranches());
    }

    public function testIsBranchReturnsTrueWithBranches(): void
    {
        $branches = [
            'option_a' => stdClass::class,
            'option_b' => stdClass::class,
        ];

        $step = new WorkflowStep(
            'branch_step',
            stdClass::class,
            [],
            null,
            $branches,
        );

        $this->assertTrue($step->isBranch());
        $this->assertSame($branches, $step->getBranches());
    }

    public function testSelectBranchReturnsNullWithoutSelector(): void
    {
        $step = new WorkflowStep('step1', stdClass::class);

        $this->assertNull($step->selectBranch([]));
    }

    public function testSelectBranchUsesSelector(): void
    {
        $branches = [
            'option_a' => stdClass::class,
            'option_b' => stdClass::class,
        ];

        $step = new WorkflowStep(
            'branch_step',
            stdClass::class,
            [],
            null,
            $branches,
            static fn($context) => $context['choice'] ?? 'option_a',
        );

        $this->assertSame('option_a', $step->selectBranch([]));
        $this->assertSame('option_b', $step->selectBranch(['choice' => 'option_b']));
    }

    public function testGetTimeoutReturnsNullByDefault(): void
    {
        $step = new WorkflowStep('step1', stdClass::class);

        $this->assertNull($step->getTimeout());
    }

    public function testGetTimeoutReturnsSetValue(): void
    {
        $step = new WorkflowStep(
            'step1',
            stdClass::class,
            [],
            null,
            null,
            null,
            300,
        );

        $this->assertSame(300, $step->getTimeout());
    }

    public function testGetRetriesDefaultsToThree(): void
    {
        $step = new WorkflowStep('step1', stdClass::class);

        $this->assertSame(3, $step->getRetries());
    }

    public function testGetRetriesReturnsSetValue(): void
    {
        $step = new WorkflowStep(
            'step1',
            stdClass::class,
            [],
            null,
            null,
            null,
            null,
            5,
        );

        $this->assertSame(5, $step->getRetries());
    }

    public function testGetRetryDelayDefaultsToSixty(): void
    {
        $step = new WorkflowStep('step1', stdClass::class);

        $this->assertSame(60, $step->getRetryDelay());
    }

    public function testGetRetryDelayReturnsSetValue(): void
    {
        $step = new WorkflowStep(
            'step1',
            stdClass::class,
            [],
            null,
            null,
            null,
            null,
            3,
            120,
        );

        $this->assertSame(120, $step->getRetryDelay());
    }

    public function testToArrayContainsAllProperties(): void
    {
        $branches = [
            'option_a' => stdClass::class,
            'option_b' => stdClass::class,
        ];

        $step = new WorkflowStep(
            'branch_step',
            stdClass::class,
            ['step1', 'step2'],
            static fn($context) => true,
            $branches,
            static fn($context) => 'option_a',
            300,
            5,
            120,
        );

        $array = $step->toArray();

        $this->assertSame('branch_step', $array['name']);
        $this->assertSame(stdClass::class, $array['job_class']);
        $this->assertSame(['step1', 'step2'], $array['dependencies']);
        $this->assertTrue($array['has_condition']);
        $this->assertTrue($array['is_branch']);
        $this->assertSame($branches, $array['branches']);
        $this->assertSame(300, $array['timeout']);
        $this->assertSame(5, $array['retries']);
        $this->assertSame(120, $array['retry_delay']);
    }
}
