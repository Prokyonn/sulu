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

namespace Sulu\Content\Tests\Unit\Content\Application\ContentWorkflow\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Content\Application\ContentWorkflow\Subscriber\WorkflowTransitionRequestCancelTransitionSubscriber;
use Sulu\Content\Domain\Exception\MissingAuthenticatedUserException;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\ExampleDimensionContent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Marking;

#[CoversClass(WorkflowTransitionRequestCancelTransitionSubscriber::class)]
class WorkflowTransitionRequestCancelTransitionSubscriberTest extends TestCase
{
    use ProphecyTrait;

    public function testGetSubscribedEvents(): void
    {
        $prefix = 'workflow.content_workflow.transition.';

        $this->assertSame(
            [
                $prefix . WorkflowInterface::WORKFLOW_TRANSITION_CANCEL_REVIEW => 'onCancelReview',
                $prefix . WorkflowInterface::WORKFLOW_TRANSITION_CANCEL_REVIEW_DRAFT => 'onCancelReview',
                $prefix . WorkflowInterface::WORKFLOW_TRANSITION_REJECT => 'onReject',
                $prefix . WorkflowInterface::WORKFLOW_TRANSITION_REJECT_DRAFT => 'onReject',
            ],
            WorkflowTransitionRequestCancelTransitionSubscriber::getSubscribedEvents(),
        );
    }

    /**
     * Cancelling is only allowed to the creator, so the subscriber has to know who is asking. Only a
     * CLI or a message consumer can get here without a token.
     */
    public function testOnCancelReviewThrowsWithoutAnAuthenticatedUser(): void
    {
        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(Argument::any())->willReturn(
            new WorkflowTransitionRequest(Example::RESOURCE_KEY, '1', 'en'),
        );

        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $tokenStorage->getToken()->willReturn(null);

        $subscriber = new WorkflowTransitionRequestCancelTransitionSubscriber(
            $repository->reveal(),
            $tokenStorage->reveal(),
        );

        $this->expectException(MissingAuthenticatedUserException::class);

        $subscriber->onCancelReview(new TransitionEvent($this->createDimensionContent(), new Marking()));
    }

    /**
     * Rejecting is the reviewer's answer, so it closes the request no matter who created it and needs
     * no token at all.
     */
    public function testOnRejectClosesTheRequestWithoutAnAuthenticatedUser(): void
    {
        $request = new WorkflowTransitionRequest(Example::RESOURCE_KEY, '1', 'en');

        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(Argument::any())->willReturn($request);

        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $tokenStorage->getToken()->shouldNotBeCalled();

        $subscriber = new WorkflowTransitionRequestCancelTransitionSubscriber(
            $repository->reveal(),
            $tokenStorage->reveal(),
        );

        $subscriber->onReject(new TransitionEvent($this->createDimensionContent(), new Marking()));

        $this->assertFalse($request->isOpen());
    }

    private function createDimensionContent(): ExampleDimensionContent
    {
        $example = new Example();
        (new \ReflectionClass($example))->getProperty('id')->setValue($example, '1');

        $dimensionContent = new ExampleDimensionContent($example);
        $dimensionContent->setLocale('en');

        return $dimensionContent;
    }
}
