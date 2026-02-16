<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Facades;

use PHPUnit\Framework\TestCase;
use Station\Core\Chain as ChainClass;
use Station\Core\Workflow as SimpleWorkflow;
use Station\Facades\Chain;
use Station\Facades\Workflow;
use stdClass;

class FacadesTest extends TestCase
{
    public function testChainFacadeCreateReturnsChainInstance(): void
    {
        $chain = Chain::create([new stdClass(), new stdClass()]);

        $this->assertInstanceOf(ChainClass::class, $chain);
    }

    public function testChainFacadeCreateWithEmptyArrayReturnsChainInstance(): void
    {
        $chain = Chain::create([]);

        $this->assertInstanceOf(ChainClass::class, $chain);
    }

    public function testWorkflowFacadeCreateReturnsWorkflowInstance(): void
    {
        $workflow = Workflow::create('test-workflow');

        $this->assertInstanceOf(SimpleWorkflow::class, $workflow);
    }
}
