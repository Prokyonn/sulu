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

namespace Sulu\Content\Tests\Unit\Content\Application\RequestWorkflow\Validator\Builtin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Content\Application\RequestWorkflow\Validator\Builtin\UserApprovalsValidator;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationContext;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;

#[CoversClass(UserApprovalsValidator::class)]
class UserApprovalsValidatorTest extends TestCase
{
    use ProphecyTrait;

    private function createRequest(): WorkflowTransitionRequest
    {
        $request = new WorkflowTransitionRequest('pages', 'test-id', 'en');
        $request->setCreator($this->prophesize(UserInterface::class)->reveal());

        return $request;
    }

    private function makeContext(WorkflowTransitionRequest $request, int $count = 1): ValidationContext
    {
        return new ValidationContext($request, ['count' => $count]);
    }

    public function testDefaultRequiredCountIsOne(): void
    {
        $validator = new UserApprovalsValidator();
        $request = $this->createRequest();

        $approver = $this->prophesize(UserInterface::class)->reveal();
        $request->addApproval($approver);

        $context = $this->makeContext($request, 1);
        $result = $validator->check($context);

        $this->assertTrue($result->passed);
        $this->assertSame([], $result->failures);
    }

    public function testPassesWhenApprovalsEqualRequiredCount(): void
    {
        $validator = new UserApprovalsValidator();
        $request = $this->createRequest();

        $userA = $this->prophesize(UserInterface::class)->reveal();
        $userB = $this->prophesize(UserInterface::class)->reveal();
        $request->addApproval($userA);
        $request->addApproval($userB);

        $context = $this->makeContext($request, 2);
        $result = $validator->check($context);

        $this->assertTrue($result->passed);
    }

    public function testPassesWhenApprovalsExceedRequiredCount(): void
    {
        $validator = new UserApprovalsValidator();
        $request = $this->createRequest();

        $userA = $this->prophesize(UserInterface::class)->reveal();
        $userB = $this->prophesize(UserInterface::class)->reveal();
        $userC = $this->prophesize(UserInterface::class)->reveal();
        $request->addApproval($userA);
        $request->addApproval($userB);
        $request->addApproval($userC);

        $context = $this->makeContext($request, 2);
        $result = $validator->check($context);

        $this->assertTrue($result->passed);
    }

    public function testFailsWhenInsufficientApprovals(): void
    {
        $validator = new UserApprovalsValidator();
        $request = $this->createRequest();

        $context = $this->makeContext($request, 1);
        $result = $validator->check($context);

        $this->assertFalse($result->passed);
        $this->assertCount(1, $result->failures);
        $this->assertSame(UserApprovalsValidator::KEY, $result->failures[0]->validatorKey);
        $this->assertSame('sulu_content.workflow_transition_request.user_approvals.insufficient', $result->failures[0]->messageKey);
    }

    public function testPassesWhenApprovalThresholdMetDespiteRejection(): void
    {
        $validator = new UserApprovalsValidator();
        $request = $this->createRequest();

        $approver = $this->prophesize(UserInterface::class)->reveal();
        $rejecter = $this->prophesize(UserInterface::class)->reveal();
        $request->addApproval($approver);
        $request->addRejection($rejecter, 'Not ready');

        // require only 1 approval — there's 1; the rejection is non-blocking
        $context = $this->makeContext($request, 1);
        $result = $validator->check($context);

        $this->assertTrue($result->passed);
        $this->assertSame([], $result->failures);
    }

    public function testRejectionDoesNotCountTowardApprovals(): void
    {
        $validator = new UserApprovalsValidator();
        $request = $this->createRequest();

        $approver = $this->prophesize(UserInterface::class)->reveal();
        $rejecter = $this->prophesize(UserInterface::class)->reveal();
        $request->addApproval($approver);
        $request->addRejection($rejecter, 'Not ready');

        // require 2 approvals — only 1 approval exists; the rejection must not fill a slot
        $context = $this->makeContext($request, 2);
        $result = $validator->check($context);

        $this->assertFalse($result->passed);
        $this->assertSame('sulu_content.workflow_transition_request.user_approvals.insufficient', $result->failures[0]->messageKey);
        $this->assertSame(['required' => 2, 'current' => 1], $result->failures[0]->details);
    }

    public function testRejectionAloneFailsAsInsufficient(): void
    {
        $validator = new UserApprovalsValidator();
        $request = $this->createRequest();

        $rejecter = $this->prophesize(UserInterface::class)->reveal();
        $request->addRejection($rejecter);

        $context = $this->makeContext($request, 1);
        $result = $validator->check($context);

        $this->assertFalse($result->passed);
        $this->assertSame('sulu_content.workflow_transition_request.user_approvals.insufficient', $result->failures[0]->messageKey);
        $this->assertSame(['required' => 1, 'current' => 0], $result->failures[0]->details);
    }

    public function testFailureContainsRequiredAndCurrentCounts(): void
    {
        $validator = new UserApprovalsValidator();
        $request = $this->createRequest();

        $context = $this->makeContext($request, 3);
        $result = $validator->check($context);

        $this->assertFalse($result->passed);
        $failure = $result->failures[0];
        $this->assertSame(['required' => 3, 'current' => 0], $failure->messageParameters);
        $this->assertSame(['required' => 3, 'current' => 0], $failure->details);
    }

    public function testSnapshotRequiredCountOverridesLiveConfig(): void
    {
        $validator = new UserApprovalsValidator();
        $request = $this->createRequest();
        $request->setRequiredApprovalCount(2);
        $request->addApproval($this->prophesize(UserInterface::class)->reveal());

        // live config says only 1 is needed, but the request snapshotted a requirement of 2
        $context = $this->makeContext($request, 1);
        $result = $validator->check($context);

        $this->assertFalse($result->passed);
        $this->assertSame(['required' => 2, 'current' => 1], $result->failures[0]->details);
    }

    public function testGetKey(): void
    {
        $validator = new UserApprovalsValidator();
        $this->assertSame('user_approvals', $validator->getKey());
    }
}
