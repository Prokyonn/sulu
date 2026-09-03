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
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Content\Application\ContentWorkflow\Subscriber\WorkflowTransitionRequestPublishGuardSubscriber;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflow;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowResolverInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\ExampleDimensionContent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Marking;

#[CoversClass(WorkflowTransitionRequestPublishGuardSubscriber::class)]
class WorkflowTransitionRequestPublishGuardSubscriberTest extends TestCase
{
    use ProphecyTrait;

    public function testGetSubscribedEvents(): void
    {
        $this->assertSame(
            [
                'workflow.content_workflow.transition.' . WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH => ['onPublishTransition', 100],
            ],
            WorkflowTransitionRequestPublishGuardSubscriber::getSubscribedEvents(),
        );
    }

    public function testOnPublishTransitionSkipsWhenNoWorkflowResolves(): void
    {
        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(Argument::any())->shouldNotBeCalled();

        $resolver = $this->prophesize(RequestWorkflowResolverInterface::class);
        $resolver->resolveForContent(Argument::any())->willReturn(null);

        $subscriber = new WorkflowTransitionRequestPublishGuardSubscriber(
            $repository->reveal(),
            $resolver->reveal(),
        );

        $dimensionContent = $this->createDimensionContent('42', 'en');
        $event = new TransitionEvent($dimensionContent, new Marking());
        $subscriber->onPublishTransition($event);
    }

    public function testOnPublishTransitionPassesWhilePendingValidatorsRemain(): void
    {
        $dimensionContent = $this->createDimensionContent('42', 'en');
        $request = $this->createApprovedRequest();
        $request->addValidator('slow_check');

        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy([
            'resourceKey' => Example::RESOURCE_KEY,
            'resourceId' => '42',
            'locale' => 'en',
            'active' => true,
        ])->willReturn($request)->shouldBeCalled();

        $resolver = $this->prophesize(RequestWorkflowResolverInterface::class);
        $resolver->resolveForContent(Argument::any())->willReturn(new RequestWorkflow('default', []));

        $subscriber = new WorkflowTransitionRequestPublishGuardSubscriber(
            $repository->reveal(),
            $resolver->reveal(),
        );

        $subscriber->onPublishTransition(new TransitionEvent($dimensionContent, new Marking()));
    }

    private function createApprovedRequest(): WorkflowTransitionRequest
    {
        $request = new WorkflowTransitionRequest(Example::RESOURCE_KEY, '42', 'en');
        $request->setRequiredApprovalCount(1);
        $request->setCreator($this->prophesize(UserInterface::class)->reveal());
        $request->addApproval($this->prophesize(UserInterface::class)->reveal());

        return $request;
    }

    private function createDimensionContent(string $id, string $locale): ExampleDimensionContent
    {
        $example = new Example();
        $reflection = new \ReflectionClass($example);
        $reflection->getProperty('id')->setValue($example, $id);

        $dimensionContent = new ExampleDimensionContent($example);
        $dimensionContent->setLocale($locale);
        $dimensionContent->setStage(DimensionContentInterface::STAGE_DRAFT);

        return $dimensionContent;
    }
}
