<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Workflows;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Station\Workflows\WorkflowDefinition;

class WorkflowDefinitionTest extends TestCase
{
    public function testDefineCreatesNewDefinition(): void
    {
        $definition = WorkflowDefinition::define('test-workflow');

        $this->assertSame('test-workflow', $definition->getName());
        $this->assertNotEmpty($definition->getId());
    }

    public function testDescriptionCanBeSet(): void
    {
        $definition = WorkflowDefinition::define('test')
            ->description('Test workflow description');

        $this->assertSame('Test workflow description', $definition->getDescription());
    }

    public function testStepsCanBeAdded(): void
    {
        $definition = WorkflowDefinition::define('test')
            ->addStep('step1', TestJob::class)
            ->addStep('step2', TestJob::class, ['step1']);

        $steps = $definition->getSteps();

        $this->assertCount(2, $steps);
        $this->assertArrayHasKey('step1', $steps);
        $this->assertArrayHasKey('step2', $steps);
        $this->assertSame(['step1'], $steps['step2']->getDependencies());
    }

    public function testConditionalStepCanBeAdded(): void
    {
        $definition = WorkflowDefinition::define('test')
            ->addConditionalStep('step1', TestJob::class, static fn($context) => $context['run'] ?? false);

        $step = $definition->getStep('step1');

        $this->assertTrue($step->hasCondition());
        $this->assertFalse($step->shouldRun([]));
        $this->assertTrue($step->shouldRun(['run' => true]));
    }

    public function testTimeoutCanBeSet(): void
    {
        $definition = WorkflowDefinition::define('test')
            ->timeout(300);

        $this->assertSame(300, $definition->getTimeout());
    }

    public function testMaxRetriesCanBeSet(): void
    {
        $definition = WorkflowDefinition::define('test')
            ->maxRetries(5);

        $this->assertSame(5, $definition->getMaxRetries());
    }

    public function testMetadataCanBeSet(): void
    {
        $definition = WorkflowDefinition::define('test')
            ->withMetadata(['key' => 'value', 'foo' => 'bar']);

        $this->assertSame(['key' => 'value', 'foo' => 'bar'], $definition->getMetadata());
    }

    public function testValidationFailsWithoutSteps(): void
    {
        $definition = WorkflowDefinition::define('test');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow must have at least one step');

        $definition->validate();
    }

    public function testValidationFailsWithMissingDependency(): void
    {
        $definition = WorkflowDefinition::define('test')
            ->addStep('step1', TestJob::class, ['missing']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Step 'step1' depends on unknown step 'missing'");

        $definition->validate();
    }

    public function testValidationFailsWithCircularDependency(): void
    {
        // Create a cycle: step1 -> step2 -> step3 -> step1
        $definition = WorkflowDefinition::define('test')
            ->addStep('step1', TestJob::class, ['step3'])
            ->addStep('step2', TestJob::class, ['step1'])
            ->addStep('step3', TestJob::class, ['step2']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow contains circular dependencies');

        $definition->validate();
    }

    public function testToArrayConvertsDefinition(): void
    {
        $definition = WorkflowDefinition::define('test')
            ->description('Test description')
            ->addStep('step1', TestJob::class)
            ->timeout(300)
            ->maxRetries(5);

        $array = $definition->toArray();

        $this->assertSame('test', $array['name']);
        $this->assertSame('Test description', $array['description']);
        $this->assertSame(300, $array['timeout']);
        $this->assertSame(5, $array['max_retries']);
        $this->assertArrayHasKey('step1', $array['steps']);
    }

    public function testGetStepReturnsNullForUnknownStep(): void
    {
        $definition = WorkflowDefinition::define('test')
            ->addStep('step1', TestJob::class);

        $this->assertNull($definition->getStep('unknown'));
    }

    public function testGetStepReturnsExistingStep(): void
    {
        $definition = WorkflowDefinition::define('test')
            ->addStep('step1', TestJob::class);

        $step = $definition->getStep('step1');

        $this->assertNotNull($step);
        $this->assertSame('step1', $step->getName());
    }

    public function testMetadataMergesWithExisting(): void
    {
        $definition = WorkflowDefinition::define('test')
            ->withMetadata(['key1' => 'value1'])
            ->withMetadata(['key2' => 'value2']);

        $metadata = $definition->getMetadata();

        $this->assertSame('value1', $metadata['key1']);
        $this->assertSame('value2', $metadata['key2']);
    }

    public function testMetadataCanOverrideValues(): void
    {
        $definition = WorkflowDefinition::define('test')
            ->withMetadata(['key' => 'original'])
            ->withMetadata(['key' => 'updated']);

        $this->assertSame('updated', $definition->getMetadata()['key']);
    }

    public function testAddBranchCreatesStep(): void
    {
        $definition = WorkflowDefinition::define('test')
            ->addStep('prepare', TestJob::class)
            ->addBranch(
                'branch',
                static fn($context) => $context['type'] ?? 'default',
                [
                    'typeA' => TestJob::class,
                    'typeB' => AnotherTestJob::class,
                ],
                ['prepare'],
            );

        $step = $definition->getStep('branch');

        $this->assertNotNull($step);
        $this->assertSame(['prepare'], $step->getDependencies());
        $this->assertTrue($step->isBranch());
    }

    public function testDefaultMaxRetriesIsThree(): void
    {
        $definition = WorkflowDefinition::define('test');

        $this->assertSame(3, $definition->getMaxRetries());
    }

    public function testDefaultTimeoutIsNull(): void
    {
        $definition = WorkflowDefinition::define('test');

        $this->assertNull($definition->getTimeout());
    }

    public function testDefaultDescriptionIsNull(): void
    {
        $definition = WorkflowDefinition::define('test');

        $this->assertNull($definition->getDescription());
    }

    public function testValidationPassesWithValidWorkflow(): void
    {
        $definition = WorkflowDefinition::define('test')
            ->addStep('step1', TestJob::class)
            ->addStep('step2', TestJob::class, ['step1'])
            ->addStep('step3', TestJob::class, ['step1', 'step2']);

        // Validation should not throw for valid workflow
        $definition->validate();

        // Verify the workflow structure is intact after validation
        $this->assertCount(3, $definition->getSteps());
    }

    public function testValidationWithDiamondDependency(): void
    {
        // Diamond: A -> B,C -> D
        $definition = WorkflowDefinition::define('test')
            ->addStep('A', TestJob::class)
            ->addStep('B', TestJob::class, ['A'])
            ->addStep('C', TestJob::class, ['A'])
            ->addStep('D', TestJob::class, ['B', 'C']);

        // Validation should pass for diamond dependency pattern
        $definition->validate();

        // Verify the diamond structure dependencies are preserved
        $stepD = $definition->getStep('D');
        $this->assertSame(['B', 'C'], $stepD->getDependencies());
    }

    public function testEachDefinitionHasUniqueId(): void
    {
        $def1 = WorkflowDefinition::define('test');
        $def2 = WorkflowDefinition::define('test');

        $this->assertNotSame($def1->getId(), $def2->getId());
    }

    public function testIdIsUuid7(): void
    {
        $definition = WorkflowDefinition::define('test');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $definition->getId(),
        );
    }

    public function testIdIsDeterministicOnSameInstance(): void
    {
        $definition = WorkflowDefinition::define('test');

        $id1 = $definition->getId();
        $id2 = $definition->getId();

        $this->assertSame($id1, $id2);
    }

    public function testToArrayIncludesMetadata(): void
    {
        $definition = WorkflowDefinition::define('test')
            ->addStep('step1', TestJob::class)
            ->withMetadata(['key' => 'value']);

        $array = $definition->toArray();

        $this->assertSame(['key' => 'value'], $array['metadata']);
    }

    public function testToArrayWithNoDescription(): void
    {
        $definition = WorkflowDefinition::define('test')
            ->addStep('step1', TestJob::class);

        $array = $definition->toArray();

        $this->assertNull($array['description']);
    }

    public function testStepsCanBeRetrieved(): void
    {
        $definition = WorkflowDefinition::define('test')
            ->addStep('step1', TestJob::class)
            ->addStep('step2', TestJob::class);

        $this->assertCount(2, $definition->getSteps());
    }

    public function testNoStepsReturnsEmptyArray(): void
    {
        $definition = WorkflowDefinition::define('test');

        $this->assertEmpty($definition->getSteps());
    }

    public function testFluentApiReturnsDefinition(): void
    {
        $definition = WorkflowDefinition::define('test');

        $this->assertSame($definition, $definition->description('desc'));
        $this->assertSame($definition, $definition->timeout(100));
        $this->assertSame($definition, $definition->maxRetries(5));
        $this->assertSame($definition, $definition->withMetadata([]));
        $this->assertSame($definition, $definition->addStep('step', TestJob::class));
    }

    public function testDirectSelfDependencyValidation(): void
    {
        // Step depends on itself
        $definition = WorkflowDefinition::define('test')
            ->addStep('step1', TestJob::class, ['step1']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow contains circular dependencies');

        $definition->validate();
    }
}

class TestJob
{
    public function handle(): void {}
}

class AnotherTestJob
{
    public function handle(): void {}
}
