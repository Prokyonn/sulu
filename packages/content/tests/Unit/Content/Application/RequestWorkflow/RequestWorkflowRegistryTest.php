<?php

declare(strict_types=1);

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Content\Tests\Unit\Content\Application\RequestWorkflow;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflow;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowRegistry;
use Sulu\Content\Domain\Exception\UnknownRequestWorkflowException;

#[CoversClass(RequestWorkflowRegistry::class)]
class RequestWorkflowRegistryTest extends TestCase
{
    private function makeWorkflow(string $name): RequestWorkflow
    {
        return new RequestWorkflow($name, null, []);
    }

    public function testGetReturnsRegisteredWorkflow(): void
    {
        $workflow = $this->makeWorkflow('default');
        $registry = new RequestWorkflowRegistry([$workflow]);

        $this->assertSame($workflow, $registry->get('default'));
    }

    public function testGetThrowsForUnknownWorkflow(): void
    {
        $registry = new RequestWorkflowRegistry([]);

        $this->expectException(UnknownRequestWorkflowException::class);
        $registry->get('unknown');
    }

    public function testHasReturnsTrueForRegisteredWorkflow(): void
    {
        $workflow = $this->makeWorkflow('blog');
        $registry = new RequestWorkflowRegistry([$workflow]);

        $this->assertTrue($registry->has('blog'));
    }

    public function testHasReturnsFalseForMissingWorkflow(): void
    {
        $registry = new RequestWorkflowRegistry([]);

        $this->assertFalse($registry->has('missing'));
    }

    public function testAllReturnsAllRegisteredWorkflows(): void
    {
        $wf1 = $this->makeWorkflow('default');
        $wf2 = $this->makeWorkflow('blog');
        $registry = new RequestWorkflowRegistry([$wf1, $wf2]);

        $all = $registry->all();

        $this->assertCount(2, $all);
        $this->assertContains($wf1, $all);
        $this->assertContains($wf2, $all);
    }

    public function testAllReturnsEmptyListWhenNoWorkflowsRegistered(): void
    {
        $registry = new RequestWorkflowRegistry([]);

        $this->assertSame([], $registry->all());
    }

    public function testGetThrowsExceptionWithCorrectWorkflowName(): void
    {
        $registry = new RequestWorkflowRegistry([]);

        try {
            $registry->get('nonexistent');
            $this->fail('Expected UnknownRequestWorkflowException');
        } catch (UnknownRequestWorkflowException $e) {
            $this->assertSame('nonexistent', $e->workflowName);
        }
    }
}
