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
use Sulu\Content\Application\ContentWorkflow\ContentWorkflowInterface;
use Sulu\Content\Application\ContentWorkflow\Subscriber\WorkflowTransitionRequestPublishGuardSubscriber;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflow;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowEvaluatorInterface;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowResolverInterface;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationResult;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestNotApprovedException;
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

    public function testOnPublishTransitionSkipsWhenNotDimensionContent(): void
    {
        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(Argument::any())->shouldNotBeCalled();

        $evaluator = $this->prophesize(RequestWorkflowEvaluatorInterface::class);
        $evaluator->evaluate(Argument::any(), Argument::any())->shouldNotBeCalled();

        $resolver = $this->prophesize(RequestWorkflowResolverInterface::class);
        $resolver->resolveForContent(Argument::any())->willReturn(new RequestWorkflow('default', null, []));

        $subscriber = new WorkflowTransitionRequestPublishGuardSubscriber(
            $repository->reveal(),
            $evaluator->reveal(),
            $resolver->reveal(),
        );

        $event = new TransitionEvent(new \stdClass(), new Marking());
        $subscriber->onPublishTransition($event);
    }

    public function testOnPublishTransitionSkipsWhenNoWorkflowResolves(): void
    {
        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(Argument::any())->shouldNotBeCalled();

        $evaluator = $this->prophesize(RequestWorkflowEvaluatorInterface::class);
        $evaluator->evaluate(Argument::any(), Argument::any())->shouldNotBeCalled();

        $resolver = $this->prophesize(RequestWorkflowResolverInterface::class);
        $resolver->resolveForContent(Argument::any())->willReturn(null);

        $subscriber = new WorkflowTransitionRequestPublishGuardSubscriber(
            $repository->reveal(),
            $evaluator->reveal(),
            $resolver->reveal(),
        );

        $dimensionContent = $this->createDimensionContent('42', 'en');
        $event = new TransitionEvent($dimensionContent, new Marking());
        $subscriber->onPublishTransition($event);
    }

    public function testOnPublishTransitionForceContextSkipsValidation(): void
    {
        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(Argument::any())->shouldNotBeCalled();

        $evaluator = $this->prophesize(RequestWorkflowEvaluatorInterface::class);
        $evaluator->evaluate(Argument::any(), Argument::any())->shouldNotBeCalled();

        $resolver = $this->prophesize(RequestWorkflowResolverInterface::class);
        $resolver->resolveForContent(Argument::any())->willReturn(new RequestWorkflow('default', null, []));

        $subscriber = new WorkflowTransitionRequestPublishGuardSubscriber(
            $repository->reveal(),
            $evaluator->reveal(),
            $resolver->reveal(),
        );

        $dimensionContent = $this->createDimensionContent('1', 'en');
        $event = new TransitionEvent(
            $dimensionContent,
            new Marking(),
            null,
            null,
            [ContentWorkflowInterface::FORCE_CONTEXT_KEY => true],
        );
        $subscriber->onPublishTransition($event);
    }

    public function testOnPublishTransitionSkipsWhenLocaleIsNull(): void
    {
        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(Argument::any())->shouldNotBeCalled();

        $evaluator = $this->prophesize(RequestWorkflowEvaluatorInterface::class);
        $evaluator->evaluate(Argument::any(), Argument::any())->shouldNotBeCalled();

        $resolver = $this->prophesize(RequestWorkflowResolverInterface::class);
        $resolver->resolveForContent(Argument::any())->willReturn(new RequestWorkflow('default', null, []));

        $subscriber = new WorkflowTransitionRequestPublishGuardSubscriber(
            $repository->reveal(),
            $evaluator->reveal(),
            $resolver->reveal(),
        );

        $dimensionContent = $this->createDimensionContent('1', null);
        $event = new TransitionEvent($dimensionContent, new Marking());
        $subscriber->onPublishTransition($event);
    }

    public function testOnPublishTransitionThrowsWhenNoActiveRequestExists(): void
    {
        $dimensionContent = $this->createDimensionContent('42', 'en');

        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy([
            'resourceKey' => Example::RESOURCE_KEY,
            'resourceId' => '42',
            'locale' => 'en',
            'active' => true,
        ])->willReturn(null);

        $evaluator = $this->prophesize(RequestWorkflowEvaluatorInterface::class);
        $evaluator->evaluate(Argument::any(), Argument::any())->shouldNotBeCalled();

        $resolver = $this->prophesize(RequestWorkflowResolverInterface::class);
        $resolver->resolveForContent(Argument::any())->willReturn(new RequestWorkflow('default', null, []));

        $subscriber = new WorkflowTransitionRequestPublishGuardSubscriber(
            $repository->reveal(),
            $evaluator->reveal(),
            $resolver->reveal(),
        );

        $this->expectException(WorkflowTransitionRequestNotApprovedException::class);
        $event = new TransitionEvent($dimensionContent, new Marking());
        $subscriber->onPublishTransition($event);
    }

    public function testOnPublishTransitionThrowsWhenEvaluatorReturnsFailure(): void
    {
        $dimensionContent = $this->createDimensionContent('42', 'en');
        $request = new WorkflowTransitionRequest(Example::RESOURCE_KEY, '42', 'en');

        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(Argument::any())->willReturn($request);

        $evaluator = $this->prophesize(RequestWorkflowEvaluatorInterface::class);
        $evaluator->evaluate($request, $dimensionContent)->willReturn(ValidationResult::fail());

        $resolver = $this->prophesize(RequestWorkflowResolverInterface::class);
        $resolver->resolveForContent(Argument::any())->willReturn(new RequestWorkflow('default', null, []));

        $subscriber = new WorkflowTransitionRequestPublishGuardSubscriber(
            $repository->reveal(),
            $evaluator->reveal(),
            $resolver->reveal(),
        );

        $this->expectException(WorkflowTransitionRequestNotApprovedException::class);
        $event = new TransitionEvent($dimensionContent, new Marking());
        $subscriber->onPublishTransition($event);
    }

    public function testOnPublishTransitionStoresRequestInContextWhenEvaluationPasses(): void
    {
        $dimensionContent = $this->createDimensionContent('42', 'en');
        $request = new WorkflowTransitionRequest(Example::RESOURCE_KEY, '42', 'en');

        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(Argument::any())->willReturn($request);

        $evaluator = $this->prophesize(RequestWorkflowEvaluatorInterface::class);
        $evaluator->evaluate($request, $dimensionContent)->willReturn(ValidationResult::pass());

        $resolver = $this->prophesize(RequestWorkflowResolverInterface::class);
        $resolver->resolveForContent(Argument::any())->willReturn(new RequestWorkflow('default', null, []));

        $subscriber = new WorkflowTransitionRequestPublishGuardSubscriber(
            $repository->reveal(),
            $evaluator->reveal(),
            $resolver->reveal(),
        );

        $event = new TransitionEvent($dimensionContent, new Marking());
        $subscriber->onPublishTransition($event);

        $stored = WorkflowTransitionRequestPublishGuardSubscriber::readWorkflowTransitionRequest($event);
        $this->assertSame($request, $stored);
    }

    private function createDimensionContent(string $id, ?string $locale): ExampleDimensionContent
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
