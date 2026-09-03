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
use Psr\Log\LoggerInterface;
use Sulu\Content\Application\Message\ValidateWorkflowTransitionRequestMessage;
use Sulu\Content\Application\MessageHandler\ValidateWorkflowTransitionRequestMessageHandler;
use Sulu\Content\Application\MessageHandler\WorkerState;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflow;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowRegistryInterface;
use Sulu\Content\Application\RequestWorkflow\Validator\RequestWorkflowValidatorInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewerStatusEnum;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;

#[CoversClass(ValidateWorkflowTransitionRequestMessageHandler::class)]
final class ValidateWorkflowTransitionRequestMessageHandlerTest extends TestCase
{
    use ProphecyTrait;

    public function testThrowingValidatorRejectsItsOwnRowWithTheExceptionMessageInsideTheRequest(): void
    {
        $request = $this->createRequest();
        $request->addValidator('exploding');

        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(['id' => 'request-1'])->willReturn($request);
        $repository->settleValidatorReviewer(
            $request->getValidatorReviewer('exploding'),
            WorkflowTransitionRequestReviewerStatusEnum::REJECTED,
            'Check failed: remote service down',
        )->shouldBeCalledOnce();

        $exploding = $this->prophesize(RequestWorkflowValidatorInterface::class);
        $exploding->check(Argument::any())->willThrow(new \RuntimeException('remote service down'));

        $logger = $this->prophesize(LoggerInterface::class);
        $logger->error(Argument::cetera())->shouldBeCalledOnce();

        $registry = $this->prophesize(RequestWorkflowRegistryInterface::class);
        $registry->has('default')->willReturn(true);
        $registry->get('default')->willReturn(new RequestWorkflow('default', [
            'exploding' => ['validator' => $exploding->reveal(), 'config' => []],
        ]));

        $handler = new ValidateWorkflowTransitionRequestMessageHandler(
            $repository->reveal(),
            $registry->reveal(),
            $logger->reveal(),
            new WorkerState(),
        );

        $handler(new ValidateWorkflowTransitionRequestMessage('request-1'));
    }

    public function testUnregisteredWorkflowIsLoggedAndLeavesTheRowsPending(): void
    {
        $request = $this->createRequest();
        $request->addValidator('unpublished_references');

        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(['id' => 'request-1'])->willReturn($request);
        $repository->settleValidatorReviewer(Argument::cetera())->shouldNotBeCalled();

        $registry = $this->prophesize(RequestWorkflowRegistryInterface::class);
        $registry->has('default')->willReturn(false);
        $registry->get(Argument::any())->shouldNotBeCalled();

        $logger = $this->prophesize(LoggerInterface::class);
        $logger->warning(Argument::cetera())->shouldBeCalledOnce();

        $handler = new ValidateWorkflowTransitionRequestMessageHandler(
            $repository->reveal(),
            $registry->reveal(),
            $logger->reveal(),
            new WorkerState(),
        );

        $handler(new ValidateWorkflowTransitionRequestMessage('request-1'));

        $this->assertSame(
            WorkflowTransitionRequestReviewerStatusEnum::PENDING,
            $request->getReviewers()[0]->getStatus(),
            'A workflow dropped from the configuration cannot answer for its validators.',
        );
    }

    private function createRequest(): WorkflowTransitionRequest
    {
        $request = new WorkflowTransitionRequest('pages', 'resource-1', 'en');
        $request->setRequiredApprovalCount(1);

        return $request;
    }
}
