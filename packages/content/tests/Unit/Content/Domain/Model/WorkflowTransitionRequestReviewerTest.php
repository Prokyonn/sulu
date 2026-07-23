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
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewer;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewerStatusEnum;

#[CoversClass(WorkflowTransitionRequestReviewer::class)]
#[CoversClass(WorkflowTransitionRequestReviewerStatusEnum::class)]
class WorkflowTransitionRequestReviewerTest extends TestCase
{
    use ProphecyTrait;

    public function testConstructCreatesApprovedReviewer(): void
    {
        $user = $this->prophesize(UserInterface::class)->reveal();
        $reviewer = new WorkflowTransitionRequestReviewer($user, WorkflowTransitionRequestReviewerStatusEnum::APPROVED, 'Looks good');

        $this->assertNotSame('', $reviewer->getId());
        $this->assertSame(WorkflowTransitionRequestReviewerStatusEnum::APPROVED, $reviewer->getStatus());
        $this->assertSame('Looks good', $reviewer->getComment());
        $this->assertSame($user, $reviewer->getCreator());
    }

    public function testConstructCreatesRejectedReviewer(): void
    {
        $user = $this->prophesize(UserInterface::class)->reveal();
        $reviewer = new WorkflowTransitionRequestReviewer($user, WorkflowTransitionRequestReviewerStatusEnum::REJECTED, 'Needs changes');

        $this->assertSame(WorkflowTransitionRequestReviewerStatusEnum::REJECTED, $reviewer->getStatus());
        $this->assertSame('Needs changes', $reviewer->getComment());
    }

    public function testConstructWithNullCommentDefaultsToNull(): void
    {
        $user = $this->prophesize(UserInterface::class)->reveal();
        $reviewer = new WorkflowTransitionRequestReviewer($user, WorkflowTransitionRequestReviewerStatusEnum::APPROVED);

        $this->assertSame(WorkflowTransitionRequestReviewerStatusEnum::APPROVED, $reviewer->getStatus());
        $this->assertNull($reviewer->getComment());
    }

    public function testUpdateChangesStatusAndComment(): void
    {
        $user = $this->prophesize(UserInterface::class)->reveal();
        $reviewer = new WorkflowTransitionRequestReviewer($user, WorkflowTransitionRequestReviewerStatusEnum::REJECTED, 'Needs changes');

        $reviewer->update(WorkflowTransitionRequestReviewerStatusEnum::APPROVED, 'Changed mind');

        $this->assertSame(WorkflowTransitionRequestReviewerStatusEnum::APPROVED, $reviewer->getStatus());
        $this->assertSame('Changed mind', $reviewer->getComment());
    }

    public function testUpdateClearsCommentWhenNull(): void
    {
        $user = $this->prophesize(UserInterface::class)->reveal();
        $reviewer = new WorkflowTransitionRequestReviewer($user, WorkflowTransitionRequestReviewerStatusEnum::REJECTED, 'Needs changes');

        $reviewer->update(WorkflowTransitionRequestReviewerStatusEnum::APPROVED);

        $this->assertNull($reviewer->getComment());
    }
}
