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
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestCancelNotAllowedException;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestClosedException;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestLifecycleEnum;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewerStatusEnum;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestStatusEnum;

#[CoversClass(WorkflowTransitionRequest::class)]
#[CoversClass(WorkflowTransitionRequestLifecycleEnum::class)]
#[CoversClass(WorkflowTransitionRequestStatusEnum::class)]
class WorkflowTransitionRequestTest extends TestCase
{
    use ProphecyTrait;

    public function testConstructInitializesOpenRequest(): void
    {
        $request = $this->createRequest();

        $this->assertNotSame('', $request->getId());
        $this->assertSame(RequestWorkflow::DEFAULT_NAME, $request->getWorkflowName());
        $this->assertSame(WorkflowTransitionRequestStatusEnum::PENDING, $request->getStatus());
        $this->assertSame('pages:4d3e0d90-4cc8-46c4-a6dc-9f0ad643f5a0:en', $request->getActiveKey());
        $this->assertEqualsWithDelta(new \DateTimeImmutable(), $request->getRequestedAt(), 5);
    }

    public function testApprovalBelowThresholdKeepsRequestPending(): void
    {
        $request = $this->createRequest();
        $request->setRequiredApprovalCount(2);

        $request->addApproval($this->prophesize(UserInterface::class)->reveal(), 'Looks good');

        $this->assertSame(WorkflowTransitionRequestStatusEnum::PENDING, $request->getStatus());
        $this->assertCount(1, $request->getReviewers());
        $this->assertSame('Looks good', $request->getReviewers()[0]->getComment());
        $this->assertNotNull($request->getReviewers()[0]->getDecidedAt());
    }

    public function testRejectionsDoNotBlockTheRequiredApprovals(): void
    {
        $request = $this->createRequest();
        $request->setRequiredApprovalCount(3);

        $request->addApproval($this->prophesize(UserInterface::class)->reveal());
        $request->addApproval($this->prophesize(UserInterface::class)->reveal());
        $request->addApproval($this->prophesize(UserInterface::class)->reveal());
        $request->addRejection($this->prophesize(UserInterface::class)->reveal(), 'Typo in the headline');
        $request->addRejection($this->prophesize(UserInterface::class)->reveal(), 'Wrong image');

        $this->assertSame(
            WorkflowTransitionRequestStatusEnum::APPROVED,
            $request->getStatus(),
            'A rejection is a comment that does not count, it never vetoes the approvals.',
        );
    }

    public function testValidatorApprovalCountsTowardsTheThreshold(): void
    {
        $request = $this->createRequest();
        $request->setRequiredApprovalCount(2);
        $request->addValidator('unpublished_references');

        $request->addApproval($this->prophesize(UserInterface::class)->reveal());

        $this->assertSame(
            WorkflowTransitionRequestStatusEnum::PENDING,
            $request->getStatus(),
            'A validator that has not answered yet counts as nothing.',
        );

        $request->getValidatorReviewer('unpublished_references')?->approve();

        $this->assertSame(
            WorkflowTransitionRequestStatusEnum::APPROVED,
            $request->getStatus(),
            'A passed check counts like a person\'s approval.',
        );
    }

    public function testValidatorRejectionLeavesTheHumansInCharge(): void
    {
        $request = $this->createRequest();
        $request->setRequiredApprovalCount(1);
        $request->addValidator('unpublished_references');
        $request->getValidatorReviewer('unpublished_references')?->reject('2 selected examples are not published: 1, 2');

        $this->assertSame(WorkflowTransitionRequestStatusEnum::PENDING, $request->getStatus());

        $request->addApproval($this->prophesize(UserInterface::class)->reveal());

        $this->assertSame(WorkflowTransitionRequestStatusEnum::APPROVED, $request->getStatus());
    }

    public function testSameUserSwitchingToRejectWithdrawsTheApproval(): void
    {
        $user = $this->prophesize(UserInterface::class)->reveal();
        $request = $this->createRequest();
        $request->setRequiredApprovalCount(1);

        $request->addApproval($user, 'Fine by me');
        $this->assertSame(WorkflowTransitionRequestStatusEnum::APPROVED, $request->getStatus());

        $request->addRejection($user, 'Changed my mind');

        $this->assertCount(1, $request->getReviewers());
        $this->assertSame(WorkflowTransitionRequestReviewerStatusEnum::REJECTED, $request->getReviewers()[0]->getStatus());
        $this->assertSame('Changed my mind', $request->getReviewers()[0]->getComment());
        $this->assertSame(WorkflowTransitionRequestStatusEnum::PENDING, $request->getStatus());
    }

    public function testZeroRequiredApprovalsApprovesWithoutReviewer(): void
    {
        $request = $this->createRequest();
        $request->setRequiredApprovalCount(0);

        $this->assertSame(WorkflowTransitionRequestStatusEnum::APPROVED, $request->getStatus());
    }

    public function testClosedRequestThrowsOnApproval(): void
    {
        $request = $this->createRequest();
        $request->cancel();

        $this->expectException(WorkflowTransitionRequestClosedException::class);
        $request->addApproval($this->prophesize(UserInterface::class)->reveal());
    }

    public function testCreatorCannotApproveOwnRequest(): void
    {
        $creator = $this->prophesize(UserInterface::class)->reveal();
        $request = $this->createRequest();
        $request->setCreator($creator);

        $this->expectException(SelfReviewNotAllowedException::class);
        $request->addApproval($creator);
    }

    public function testApprovalWithDeletedCreatorIsAllowed(): void
    {
        $request = new WorkflowTransitionRequest('pages', '4d3e0d90-4cc8-46c4-a6dc-9f0ad643f5a0', 'en');

        $request->addApproval($this->prophesize(UserInterface::class)->reveal());

        $this->assertSame(
            WorkflowTransitionRequestStatusEnum::APPROVED,
            $request->getStatus(),
            'A deleted creator nulls the column, which must not block every review of the request.',
        );
    }

    public function testCancelByCreatorClosesTheRequest(): void
    {
        $creator = $this->prophesize(UserInterface::class)->reveal();
        $request = $this->createRequest();
        $request->setCreator($creator);
        $request->addApproval($this->prophesize(UserInterface::class)->reveal());

        $request->cancelByUser($creator);

        $this->assertSame(WorkflowTransitionRequestStatusEnum::CANCELLED, $request->getStatus());
        $this->assertNull($request->getActiveKey(), 'A cancelled request must not block the next one.');
        $this->assertFalse($request->isOpen());
    }

    public function testCancelByAnotherUserIsRejected(): void
    {
        $request = $this->createRequest();

        $this->expectException(WorkflowTransitionRequestCancelNotAllowedException::class);
        $request->cancelByUser($this->prophesize(UserInterface::class)->reveal());
    }

    public function testCancelWithDeletedCreatorIsAllowed(): void
    {
        $request = new WorkflowTransitionRequest('pages', '4d3e0d90-4cc8-46c4-a6dc-9f0ad643f5a0', 'en');

        $request->cancelByUser($this->prophesize(UserInterface::class)->reveal());

        $this->assertSame(
            WorkflowTransitionRequestStatusEnum::CANCELLED,
            $request->getStatus(),
            'A deleted creator nulls the column, which must not leave the content locked forever.',
        );
    }

    public function testCancelOnAlreadyCancelledRequestIsNoOp(): void
    {
        $request = $this->createRequest();
        $request->cancel();
        $request->cancel();

        $this->assertSame(WorkflowTransitionRequestStatusEnum::CANCELLED, $request->getStatus());
    }

    public function testPublishTransitionsApprovedRequestToPublished(): void
    {
        $request = $this->createRequest();
        $request->setRequiredApprovalCount(1);
        $request->addApproval($this->prophesize(UserInterface::class)->reveal());

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

        // A creator distinct from every reviewer stub, so the self-review guard never fires by accident.
        $request->setCreator($this->prophesize(UserInterface::class)->reveal());

        return $request;
    }
}
