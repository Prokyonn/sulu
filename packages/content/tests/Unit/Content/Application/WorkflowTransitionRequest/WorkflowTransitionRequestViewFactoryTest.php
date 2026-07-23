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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflow;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowEvaluatorInterface;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowRegistryInterface;
use Sulu\Content\Application\RequestWorkflow\Validator\Builtin\UserApprovalsValidator;
use Sulu\Content\Application\WorkflowTransitionRequest\WorkflowTransitionRequestViewFactory;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;

#[CoversClass(WorkflowTransitionRequestViewFactory::class)]
class WorkflowTransitionRequestViewFactoryTest extends TestCase
{
    use ProphecyTrait;

    private function user(int $id = 1, string $fullName = 'Tester'): UserInterface
    {
        $user = $this->prophesize(UserInterface::class);
        $user->getId()->willReturn($id);
        $user->getFullName()->willReturn($fullName);

        return $user->reveal();
    }

    private function createRequest(): WorkflowTransitionRequest
    {
        $request = new WorkflowTransitionRequest('pages', 'test-id', 'en');
        $request->setCreator($this->user(1, 'Creator'));

        return $request;
    }

    public function testApprovalProgressUsesSnapshotAndCountsReviewers(): void
    {
        $factory = new WorkflowTransitionRequestViewFactory(
            $this->prophesize(RequestWorkflowEvaluatorInterface::class)->reveal(),
            $this->prophesize(RequestWorkflowRegistryInterface::class)->reveal(),
        );

        $request = $this->createRequest();
        $request->setRequiredApprovalCount(3);
        $request->addApproval($this->user(2, 'Reviewer A'));
        $request->addRejection($this->user(3, 'Reviewer B'));
        foreach ($request->getReviewers() as $reviewer) {
            $reviewer->setChanged(new \DateTimeImmutable('2026-01-01 10:00:00'));
        }

        $view = $factory->build($request);

        $this->assertSame(
            ['required' => 3, 'approved' => 1, 'rejected' => 1, 'remainingApprovals' => 2],
            $view['approvalProgress'],
        );
    }

    public function testPublishValidationIsNullWithoutDimensionContent(): void
    {
        $factory = new WorkflowTransitionRequestViewFactory(
            $this->prophesize(RequestWorkflowEvaluatorInterface::class)->reveal(),
            $this->prophesize(RequestWorkflowRegistryInterface::class)->reveal(),
        );

        $request = $this->createRequest();
        $request->setRequiredApprovalCount(1);

        $view = $factory->build($request);

        $this->assertNull($view['publishValidation']);
        $this->assertSame('pending', $view['status']);
        $this->assertSame('pages', $view['resourceKey']);
    }

    public function testApprovalProgressFallsBackToRegistryWhenNoSnapshot(): void
    {
        $registry = $this->prophesize(RequestWorkflowRegistryInterface::class);
        $registry->has('default')->willReturn(true);
        $registry->get('default')->willReturn(new RequestWorkflow('default', null, [
            ['validator' => new UserApprovalsValidator(), 'config' => ['count' => 2]],
        ]));

        $factory = new WorkflowTransitionRequestViewFactory(
            $this->prophesize(RequestWorkflowEvaluatorInterface::class)->reveal(),
            $registry->reveal(),
        );

        $view = $factory->build($this->createRequest());

        $this->assertSame(2, $view['approvalProgress']['required']);
        $this->assertSame(0, $view['approvalProgress']['approved']);
        $this->assertSame(2, $view['approvalProgress']['remainingApprovals']);
    }
}
