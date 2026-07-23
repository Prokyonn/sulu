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

namespace Sulu\Content\Tests\Unit\Content\Domain\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflow;
use Sulu\Content\Domain\Exception\SelfReviewNotAllowedException;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestClosedException;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewerStatusEnum;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestStatusEnum;

#[CoversClass(WorkflowTransitionRequest::class)]
#[CoversClass(WorkflowTransitionRequestStatusEnum::class)]
class WorkflowTransitionRequestTest extends TestCase
{
    use ProphecyTrait;

    public function testConstructInitializesOpenRequest(): void
    {
        $request = $this->createRequest();

        $this->assertNotSame('', $request->getId());
        $this->assertSame(WorkflowTransitionRequestStatusEnum::PENDING, $request->getStatus());
        $this->assertSame('pages:4d3e0d90-4cc8-46c4-a6dc-9f0ad643f5a0:en', $request->getActiveKey());
        $this->assertEqualsWithDelta(new \DateTimeImmutable(), $request->getRequestedAt(), 5);
    }

    public function testConstructDefaultsWorkflowName(): void
    {
        $request = $this->createRequest();

        $this->assertSame(RequestWorkflow::DEFAULT_NAME, $request->getWorkflowName());
    }

    public function testConstructWithExplicitWorkflowName(): void
    {
        $request = new WorkflowTransitionRequest('pages', '4d3e0d90-4cc8-46c4-a6dc-9f0ad643f5a0', 'en', 'custom');

        $this->assertSame('custom', $request->getWorkflowName());
    }

    public function testRequiredApprovalCountDefaultsToNull(): void
    {
        $request = $this->createRequest();

        $this->assertNull($request->getRequiredApprovalCount());
    }

    public function testRequiredApprovalCountCanBeSnapshotted(): void
    {
        $request = $this->createRequest();

        $request->setRequiredApprovalCount(3);

        $this->assertSame(3, $request->getRequiredApprovalCount());
    }

    public function testAddApprovalUpsertesReviewerRowWithoutChangingStatus(): void
    {
        $user = $this->prophesize(UserInterface::class)->reveal();
        $request = $this->createRequest();

        $request->addApproval($user, 'Looks good');

        // Status is NOT flipped by addApproval — that is done by the evaluator.
        $this->assertSame(WorkflowTransitionRequestStatusEnum::PENDING, $request->getStatus());
        $this->assertSame('pages:4d3e0d90-4cc8-46c4-a6dc-9f0ad643f5a0:en', $request->getActiveKey());
        $this->assertCount(1, $request->getReviewers());
        $this->assertSame('Looks good', $request->getReviewers()[0]->getComment());
    }

    public function testAddRejectionUpsertesReviewerRowWithoutChangingStatus(): void
    {
        $user = $this->prophesize(UserInterface::class)->reveal();
        $request = $this->createRequest();

        $request->addRejection($user, 'Needs changes');

        // Status is NOT flipped by addRejection — that is done by the evaluator.
        $this->assertSame(WorkflowTransitionRequestStatusEnum::PENDING, $request->getStatus());
        $this->assertSame('pages:4d3e0d90-4cc8-46c4-a6dc-9f0ad643f5a0:en', $request->getActiveKey());
        $this->assertCount(1, $request->getReviewers());
        $this->assertSame('Needs changes', $request->getReviewers()[0]->getComment());
    }

    public function testSameUserChangingMindUpdatesExistingReviewer(): void
    {
        $user = $this->prophesize(UserInterface::class)->reveal();
        $request = $this->createRequest();

        $request->addRejection($user, 'Needs changes');
        $request->addApproval($user, 'Changed mind');

        $this->assertCount(1, $request->getReviewers());
        $this->assertSame(WorkflowTransitionRequestReviewerStatusEnum::APPROVED, $request->getReviewers()[0]->getStatus());
        $this->assertSame('Changed mind', $request->getReviewers()[0]->getComment());
    }

    public function testDifferentUsersCreateSeparateReviewers(): void
    {
        $userA = $this->prophesize(UserInterface::class)->reveal();
        $userB = $this->prophesize(UserInterface::class)->reveal();
        $request = $this->createRequest();

        $request->addApproval($userA);
        $request->addApproval($userB);

        $this->assertCount(2, $request->getReviewers());
    }

    public function testRejectedRequestAllowsFurtherReviewerActions(): void
    {
        $userA = $this->prophesize(UserInterface::class)->reveal();
        $userB = $this->prophesize(UserInterface::class)->reveal();
        $request = $this->createRequest();

        $request->addRejection($userA, 'Nope');
        $request->addApproval($userB);

        $this->assertCount(2, $request->getReviewers());
    }

    public function testUpdateStatusSetsStatusAndSyncsActiveKey(): void
    {
        $request = $this->createRequest();

        $request->updateStatus(WorkflowTransitionRequestStatusEnum::APPROVED);

        $this->assertSame(WorkflowTransitionRequestStatusEnum::APPROVED, $request->getStatus());
        $this->assertSame('pages:4d3e0d90-4cc8-46c4-a6dc-9f0ad643f5a0:en', $request->getActiveKey());
    }

    public function testUpdateStatusToRejectedSyncsActiveKey(): void
    {
        $request = $this->createRequest();

        $request->updateStatus(WorkflowTransitionRequestStatusEnum::REJECTED);

        $this->assertSame(WorkflowTransitionRequestStatusEnum::REJECTED, $request->getStatus());
        $this->assertSame('pages:4d3e0d90-4cc8-46c4-a6dc-9f0ad643f5a0:en', $request->getActiveKey());
    }

    public function testUpdateStatusDoesNotOverwriteClosedStatus(): void
    {
        $request = $this->createRequest();
        $request->cancel();

        $request->updateStatus(WorkflowTransitionRequestStatusEnum::APPROVED);

        $this->assertSame(WorkflowTransitionRequestStatusEnum::CANCELLED, $request->getStatus());
    }

    public function testClosedRequestThrowsOnApproval(): void
    {
        $user = $this->prophesize(UserInterface::class)->reveal();
        $request = $this->createRequest();
        $request->cancel();

        $this->expectException(WorkflowTransitionRequestClosedException::class);
        $request->addApproval($user);
    }

    public function testClosedRequestThrowsOnRejection(): void
    {
        $user = $this->prophesize(UserInterface::class)->reveal();
        $request = $this->createRequest();
        $request->cancel();

        $this->expectException(WorkflowTransitionRequestClosedException::class);
        $request->addRejection($user);
    }

    public function testCreatorCannotApproveOwnRequest(): void
    {
        $creator = $this->prophesize(UserInterface::class)->reveal();
        $request = $this->createRequest();
        $request->setCreator($creator); // override the default creator from createRequest()

        $this->expectException(SelfReviewNotAllowedException::class);
        $request->addApproval($creator);
    }

    public function testCreatorCannotRejectOwnRequest(): void
    {
        $creator = $this->prophesize(UserInterface::class)->reveal();
        $request = $this->createRequest();
        $request->setCreator($creator); // override the default creator from createRequest()

        $this->expectException(SelfReviewNotAllowedException::class);
        $request->addRejection($creator, 'No thanks');
    }

    public function testApprovalWithoutCreatorThrowsLogicException(): void
    {
        $user = $this->prophesize(UserInterface::class)->reveal();
        $request = new WorkflowTransitionRequest('pages', '4d3e0d90-4cc8-46c4-a6dc-9f0ad643f5a0', 'en');

        $this->expectException(\LogicException::class);
        $request->addApproval($user);
    }

    public function testCancelTransitionsToTerminalStateAndClearsActiveKey(): void
    {
        $request = $this->createRequest();
        $request->cancel();

        $this->assertSame(WorkflowTransitionRequestStatusEnum::CANCELLED, $request->getStatus());
        $this->assertNull($request->getActiveKey());
    }

    public function testCancelOnAlreadyCancelledRequestIsNoOp(): void
    {
        $request = $this->createRequest();
        $request->cancel();
        $request->cancel();

        $this->assertSame(WorkflowTransitionRequestStatusEnum::CANCELLED, $request->getStatus());
    }

    public function testMarkPublishedTransitionsApprovedRequestToPublished(): void
    {
        $request = $this->createRequest();
        $request->updateStatus(WorkflowTransitionRequestStatusEnum::APPROVED);

        $request->publish();

        $this->assertSame(WorkflowTransitionRequestStatusEnum::PUBLISHED, $request->getStatus());
        $this->assertNull($request->getActiveKey());
    }

    private function createRequest(): WorkflowTransitionRequest
    {
        $request = new WorkflowTransitionRequest(
            resourceKey: 'pages',
            resourceId: '4d3e0d90-4cc8-46c4-a6dc-9f0ad643f5a0',
            locale: 'en',
        );

        // Tests that compare reviewers to the creator set their own creator explicitly. Default to a
        // distinct stub so the self-review guard has something non-null to evaluate against without
        // accidentally matching one of the test reviewer stubs.
        $request->setCreator($this->prophesize(UserInterface::class)->reveal());

        return $request;
    }
}
