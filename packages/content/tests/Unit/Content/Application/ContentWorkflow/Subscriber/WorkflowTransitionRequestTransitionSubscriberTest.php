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

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Content\Application\ContentWorkflow\Subscriber\WorkflowTransitionRequestTransitionSubscriber;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflow;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowResolverInterface;
use Sulu\Content\Application\RequestWorkflow\Validator\Builtin\UserApprovalsValidator;
use Sulu\Content\Domain\Exception\DuplicateActiveWorkflowTransitionRequestException;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\ExampleDimensionContent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Marking;

#[CoversClass(WorkflowTransitionRequestTransitionSubscriber::class)]
class WorkflowTransitionRequestTransitionSubscriberTest extends TestCase
{
    use ProphecyTrait;

    public function testGetSubscribedEvents(): void
    {
        $this->assertSame(
            [
                'workflow.content_workflow.transition.' . WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW => 'onRequestForReview',
                'workflow.content_workflow.transition.' . WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT => 'onRequestForReview',
            ],
            WorkflowTransitionRequestTransitionSubscriber::getSubscribedEvents(),
        );
    }

    public function testOnRequestForReviewSkipsCreationWhenResolverReturnsNull(): void
    {
        $example = $this->createExample('42');
        $dimensionContent = $this->createDimensionContent($example, 'en');

        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(Argument::any())->shouldNotBeCalled();
        $repository->add(Argument::any())->shouldNotBeCalled();

        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $tokenStorage->getToken()->shouldNotBeCalled();

        $resolver = $this->prophesize(RequestWorkflowResolverInterface::class);
        $resolver->resolveForContent($dimensionContent)->willReturn(null);

        $entityManager = $this->prophesize(EntityManagerInterface::class);
        $entityManager->flush()->shouldNotBeCalled();

        $subscriber = new WorkflowTransitionRequestTransitionSubscriber(
            $repository->reveal(),
            $tokenStorage->reveal(),
            $resolver->reveal(),
            $entityManager->reveal(),
        );

        $event = new TransitionEvent($dimensionContent, new Marking());
        $subscriber->onRequestForReview($event);
    }

    public function testOnRequestForReviewStampsWorkflowNameFromResolver(): void
    {
        $user = $this->prophesize(UserInterface::class)->reveal();

        $example = $this->createExample('99');
        $dimensionContent = $this->createDimensionContent($example, 'de');

        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(Argument::any())->willReturn(null);
        $capturedRequest = null;
        $repository->add(Argument::type(WorkflowTransitionRequest::class))
            ->will(static function(array $args) use (&$capturedRequest): void {
                $capturedRequest = $args[0];
            });

        $token = $this->prophesize(TokenInterface::class);
        $token->getUser()->willReturn($user);
        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $tokenStorage->getToken()->willReturn($token->reveal());

        $requestWorkflow = new RequestWorkflow('custom', null, []);
        $resolver = $this->prophesize(RequestWorkflowResolverInterface::class);
        $resolver->resolveForContent($dimensionContent)->willReturn($requestWorkflow);

        $subscriber = new WorkflowTransitionRequestTransitionSubscriber(
            $repository->reveal(),
            $tokenStorage->reveal(),
            $resolver->reveal(),
            $this->prophesize(EntityManagerInterface::class)->reveal(),
        );

        $event = new TransitionEvent($dimensionContent, new Marking());
        $subscriber->onRequestForReview($event);

        $this->assertNotNull($capturedRequest);
        $this->assertInstanceOf(WorkflowTransitionRequest::class, $capturedRequest);
        $this->assertSame('custom', $capturedRequest->getWorkflowName());
    }

    public function testOnRequestForReviewSnapshotsRequiredApprovalCountFromWorkflow(): void
    {
        $user = $this->prophesize(UserInterface::class)->reveal();

        $example = $this->createExample('7');
        $dimensionContent = $this->createDimensionContent($example, 'en');

        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(Argument::any())->willReturn(null);
        $capturedRequest = null;
        $repository->add(Argument::type(WorkflowTransitionRequest::class))
            ->will(static function(array $args) use (&$capturedRequest): void {
                $capturedRequest = $args[0];
            });

        $token = $this->prophesize(TokenInterface::class);
        $token->getUser()->willReturn($user);
        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $tokenStorage->getToken()->willReturn($token->reveal());

        $requestWorkflow = new RequestWorkflow('default', null, [
            ['validator' => new UserApprovalsValidator(), 'config' => ['count' => 2]],
        ]);
        $resolver = $this->prophesize(RequestWorkflowResolverInterface::class);
        $resolver->resolveForContent($dimensionContent)->willReturn($requestWorkflow);

        $subscriber = new WorkflowTransitionRequestTransitionSubscriber(
            $repository->reveal(),
            $tokenStorage->reveal(),
            $resolver->reveal(),
            $this->prophesize(EntityManagerInterface::class)->reveal(),
        );

        $event = new TransitionEvent($dimensionContent, new Marking());
        $subscriber->onRequestForReview($event);

        $this->assertInstanceOf(WorkflowTransitionRequest::class, $capturedRequest);
        $this->assertSame(2, $capturedRequest->getRequiredApprovalCount());
    }

    public function testOnRequestForReviewThrowsWhenActiveRequestAlreadyExists(): void
    {
        $example = $this->createExample('1');
        $dimensionContent = $this->createDimensionContent($example, 'en');

        $existingRequest = new WorkflowTransitionRequest(Example::RESOURCE_KEY, '1', 'en');

        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(Argument::any())->willReturn($existingRequest);
        $repository->add(Argument::any())->shouldNotBeCalled();

        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $resolver = $this->prophesize(RequestWorkflowResolverInterface::class);
        $resolver->resolveForContent($dimensionContent)->willReturn(new RequestWorkflow('default', null, []));

        $subscriber = new WorkflowTransitionRequestTransitionSubscriber(
            $repository->reveal(),
            $tokenStorage->reveal(),
            $resolver->reveal(),
            $this->prophesize(EntityManagerInterface::class)->reveal(),
        );

        $this->expectException(DuplicateActiveWorkflowTransitionRequestException::class);
        $event = new TransitionEvent($dimensionContent, new Marking());
        $subscriber->onRequestForReview($event);
    }

    public function testOnRequestForReviewSkipsWhenNotDimensionContent(): void
    {
        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->add(Argument::any())->shouldNotBeCalled();

        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $resolver = $this->prophesize(RequestWorkflowResolverInterface::class);

        $subscriber = new WorkflowTransitionRequestTransitionSubscriber(
            $repository->reveal(),
            $tokenStorage->reveal(),
            $resolver->reveal(),
            $this->prophesize(EntityManagerInterface::class)->reveal(),
        );

        $subject = new \stdClass();
        $event = new TransitionEvent($subject, new Marking());
        $subscriber->onRequestForReview($event);
    }

    public function testOnRequestForReviewSkipsWhenLocaleIsNull(): void
    {
        $example = $this->createExample('1');
        $dimensionContent = $this->createDimensionContent($example, null);

        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->add(Argument::any())->shouldNotBeCalled();

        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $resolver = $this->prophesize(RequestWorkflowResolverInterface::class);

        $subscriber = new WorkflowTransitionRequestTransitionSubscriber(
            $repository->reveal(),
            $tokenStorage->reveal(),
            $resolver->reveal(),
            $this->prophesize(EntityManagerInterface::class)->reveal(),
        );

        $event = new TransitionEvent($dimensionContent, new Marking());
        $subscriber->onRequestForReview($event);
    }

    private function createExample(string $id): Example
    {
        $example = new Example();
        $reflection = new \ReflectionClass($example);
        $property = $reflection->getProperty('id');
        $property->setValue($example, $id);

        return $example;
    }

    private function createDimensionContent(Example $example, ?string $locale): ExampleDimensionContent
    {
        $dimensionContent = new ExampleDimensionContent($example);
        $dimensionContent->setLocale($locale);

        return $dimensionContent;
    }
}
