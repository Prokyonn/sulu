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

namespace Sulu\Content\Tests\Unit\Content\Application\WorkflowTransitionRequest;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Content\Application\WorkflowTransitionRequest\WorkflowTransitionRequestListEnhancer;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;

#[CoversClass(WorkflowTransitionRequestListEnhancer::class)]
class WorkflowTransitionRequestListEnhancerTest extends TestCase
{
    use ProphecyTrait;

    public function testEnhanceRowsReturnsRowsUnchangedWhenLocaleIsNull(): void
    {
        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findBy(Argument::any())->shouldNotBeCalled();

        $enhancer = new WorkflowTransitionRequestListEnhancer($repository->reveal(), $this->prophesize(EntityManagerInterface::class)->reveal());
        $rows = [['id' => 1, 'title' => 'A']];

        $this->assertSame($rows, $enhancer->enhanceRows($rows, 'examples', null));
    }

    public function testEnhanceRowsReturnsEmptyArrayUnchanged(): void
    {
        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findBy(Argument::any())->shouldNotBeCalled();

        $enhancer = new WorkflowTransitionRequestListEnhancer($repository->reveal(), $this->prophesize(EntityManagerInterface::class)->reveal());

        $this->assertSame([], $enhancer->enhanceRows([], 'examples', 'en'));
    }

    public function testEnhanceRowsAddsWorkflowTransitionRequestStatus(): void
    {
        $request = new WorkflowTransitionRequest('examples', '1', 'en');

        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findBy([
            'resourceKey' => 'examples',
            'resourceIds' => ['1', '2'],
            'locale' => 'en',
            'active' => true,
        ])->willReturn([$request]);

        $enhancer = new WorkflowTransitionRequestListEnhancer($repository->reveal(), $this->prophesize(EntityManagerInterface::class)->reveal());
        $rows = [
            ['id' => 1, 'title' => 'A'],
            ['id' => 2, 'title' => 'B'],
        ];

        $result = $enhancer->enhanceRows($rows, 'examples', 'en');

        $this->assertSame('pending', $result[0]['workflowTransitionRequestStatus']);
        $this->assertNull($result[1]['workflowTransitionRequestStatus']);
    }

    // findResourceIdsWithActiveRequest is exercised against the real DB in
    // WorkflowTransitionRequestListIntegrationTest::testFilteringByHasActiveWorkflowTransitionRequestRestrictsRows.
    // It uses a scalar DQL query that's awkward to mock in isolation, so this unit test class only
    // covers the row-enhancement path that doesn't touch DQL.
}
