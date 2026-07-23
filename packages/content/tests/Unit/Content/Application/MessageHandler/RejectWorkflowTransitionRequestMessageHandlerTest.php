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

namespace Sulu\Content\Tests\Unit\Content\Application\MessageHandler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Content\Application\Message\RejectWorkflowTransitionRequestMessage;
use Sulu\Content\Application\MessageHandler\RejectWorkflowTransitionRequestMessageHandler;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowEvaluatorInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

#[CoversClass(RejectWorkflowTransitionRequestMessageHandler::class)]
class RejectWorkflowTransitionRequestMessageHandlerTest extends TestCase
{
    use ProphecyTrait;

    public function testInvokeAddsRejectionAndRefreshesStatus(): void
    {
        $requestId = 'some-uuid';
        $comment = 'Needs rework';

        $user = $this->prophesize(UserInterface::class)->reveal();
        $creator = $this->prophesize(UserInterface::class)->reveal();

        $request = new WorkflowTransitionRequest('pages', 'res-1', 'en');
        $request->setCreator($creator);

        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->getOneBy(['id' => $requestId])->willReturn($request);

        $token = $this->prophesize(TokenInterface::class);
        $token->getUser()->willReturn($user);

        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $tokenStorage->getToken()->willReturn($token->reveal());

        $evaluator = $this->prophesize(RequestWorkflowEvaluatorInterface::class);
        $evaluator->refreshStatus($request, null)->shouldBeCalledOnce();

        $handler = new RejectWorkflowTransitionRequestMessageHandler(
            $repository->reveal(),
            $tokenStorage->reveal(),
            $evaluator->reveal(),
        );

        $message = new RejectWorkflowTransitionRequestMessage($requestId, $comment);
        $result = $handler($message);

        $this->assertSame($request, $result);
        $this->assertCount(1, $result->getReviewers());
        $this->assertSame($comment, $result->getReviewers()[0]->getComment());
    }

    public function testInvokeThrowsWhenNoValidUser(): void
    {
        $requestId = 'some-uuid';

        $creator = $this->prophesize(UserInterface::class)->reveal();
        $request = new WorkflowTransitionRequest('pages', 'res-1', 'en');
        $request->setCreator($creator);

        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->getOneBy(['id' => $requestId])->willReturn($request);

        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $tokenStorage->getToken()->willReturn(null);

        $evaluator = $this->prophesize(RequestWorkflowEvaluatorInterface::class);
        $evaluator->refreshStatus(Argument::any(), Argument::any())->shouldNotBeCalled();

        $handler = new RejectWorkflowTransitionRequestMessageHandler(
            $repository->reveal(),
            $tokenStorage->reveal(),
            $evaluator->reveal(),
        );

        $this->expectException(\RuntimeException::class);
        $handler(new RejectWorkflowTransitionRequestMessage($requestId));
    }
}
