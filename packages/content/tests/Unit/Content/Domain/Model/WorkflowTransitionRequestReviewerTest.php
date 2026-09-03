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
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewer;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewerStatusEnum;

#[CoversClass(WorkflowTransitionRequestReviewer::class)]
#[CoversClass(WorkflowTransitionRequestReviewerStatusEnum::class)]
class WorkflowTransitionRequestReviewerTest extends TestCase
{
    use ProphecyTrait;

    public function testForUserStartsPendingWithoutValidatorKey(): void
    {
        $user = $this->prophesize(UserInterface::class)->reveal();
        $reviewer = WorkflowTransitionRequestReviewer::forUser($this->createRequest(), $user);

        $this->assertNotSame('', $reviewer->getId());
        $this->assertSame($user, $reviewer->getUser());
        $this->assertNull($reviewer->getValidatorKey());
        $this->assertFalse($reviewer->isValidator());
        $this->assertSame(WorkflowTransitionRequestReviewerStatusEnum::PENDING, $reviewer->getStatus());
        $this->assertNull($reviewer->getDecidedAt());
    }

    public function testForValidatorStartsPendingWithoutUser(): void
    {
        $reviewer = WorkflowTransitionRequestReviewer::forValidator($this->createRequest(), 'unpublished_references');

        $this->assertNull($reviewer->getUser());
        $this->assertSame('unpublished_references', $reviewer->getValidatorKey());
        $this->assertTrue($reviewer->isValidator());
        $this->assertSame(WorkflowTransitionRequestReviewerStatusEnum::PENDING, $reviewer->getStatus());
    }

    public function testRejectAndResetToPending(): void
    {
        $reviewer = WorkflowTransitionRequestReviewer::forValidator($this->createRequest(), 'unpublished_references');

        $reviewer->reject('1 selected example is not published: 4');

        $this->assertSame(WorkflowTransitionRequestReviewerStatusEnum::REJECTED, $reviewer->getStatus());
        $this->assertSame('1 selected example is not published: 4', $reviewer->getComment());
        $this->assertNotNull($reviewer->getDecidedAt());

        $reviewer->resetToPending();

        $this->assertSame(WorkflowTransitionRequestReviewerStatusEnum::PENDING, $reviewer->getStatus());
        $this->assertNull($reviewer->getComment());
        $this->assertNull($reviewer->getDecidedAt());
    }

    public function testApproveClearsAnEarlierComment(): void
    {
        $reviewer = WorkflowTransitionRequestReviewer::forUser(
            $this->createRequest(),
            $this->prophesize(UserInterface::class)->reveal(),
        );

        $reviewer->reject('Needs changes');
        $reviewer->approve();

        $this->assertSame(WorkflowTransitionRequestReviewerStatusEnum::APPROVED, $reviewer->getStatus());
        $this->assertNull($reviewer->getComment());
    }

    private function createRequest(): WorkflowTransitionRequest
    {
        return new WorkflowTransitionRequest('pages', '4d3e0d90-4cc8-46c4-a6dc-9f0ad643f5a0', 'en');
    }
}
