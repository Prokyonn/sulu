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

namespace Sulu\Content\Tests\Unit\Content\Application\ContentNormalizer\Normalizer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Content\Application\ContentNormalizer\Normalizer\WorkflowTransitionRequestNormalizer;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflow;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowResolverInterface;
use Sulu\Content\Application\WorkflowTransitionRequest\WorkflowTransitionRequestViewFactoryInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\ExampleDimensionContent;

#[CoversClass(WorkflowTransitionRequestNormalizer::class)]
class WorkflowTransitionRequestNormalizerTest extends TestCase
{
    use ProphecyTrait;

    public function testIgnoredAttributesIsAlwaysEmpty(): void
    {
        $normalizer = new WorkflowTransitionRequestNormalizer(
            $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class)->reveal(),
            $this->prophesize(RequestWorkflowResolverInterface::class)->reveal(),
            $this->prophesize(WorkflowTransitionRequestViewFactoryInterface::class)->reveal(),
        );

        $this->assertSame([], $normalizer->getIgnoredAttributes(new \stdClass()));
        $this->assertSame([], $normalizer->getIgnoredAttributes(
            $this->prophesize(DimensionContentInterface::class)->reveal(),
        ));
    }

    public function testEnhanceSkipsNonDimensionContentObject(): void
    {
        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(Argument::any())->shouldNotBeCalled();

        $normalizer = new WorkflowTransitionRequestNormalizer(
            $repository->reveal(),
            $this->prophesize(RequestWorkflowResolverInterface::class)->reveal(),
            $this->prophesize(WorkflowTransitionRequestViewFactoryInterface::class)->reveal(),
        );

        $data = ['title' => 'Hello'];
        $this->assertSame($data, $normalizer->enhance(new \stdClass(), $data));
    }

    public function testEnhanceSkipsLiveStage(): void
    {
        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(Argument::any())->shouldNotBeCalled();

        $dimensionContent = $this->prophesize(DimensionContentInterface::class);
        $dimensionContent->getStage()->willReturn(DimensionContentInterface::STAGE_LIVE);

        $normalizer = new WorkflowTransitionRequestNormalizer(
            $repository->reveal(),
            $this->prophesize(RequestWorkflowResolverInterface::class)->reveal(),
            $this->prophesize(WorkflowTransitionRequestViewFactoryInterface::class)->reveal(),
        );
        $this->assertSame([], $normalizer->enhance($dimensionContent->reveal(), []));
    }

    public function testEnhanceSkipsWhenLocaleIsNull(): void
    {
        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy(Argument::any())->shouldNotBeCalled();

        $dimensionContent = $this->prophesize(DimensionContentInterface::class);
        $dimensionContent->getStage()->willReturn(DimensionContentInterface::STAGE_DRAFT);
        $dimensionContent->getLocale()->willReturn(null);

        $normalizer = new WorkflowTransitionRequestNormalizer(
            $repository->reveal(),
            $this->prophesize(RequestWorkflowResolverInterface::class)->reveal(),
            $this->prophesize(WorkflowTransitionRequestViewFactoryInterface::class)->reveal(),
        );
        $this->assertSame([], $normalizer->enhance($dimensionContent->reveal(), []));
    }

    public function testEnhanceAddsNullWhenNoActiveRequestExists(): void
    {
        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy([
            'resourceKey' => Example::RESOURCE_KEY,
            'resourceId' => '7',
            'locale' => 'en',
            'active' => true,
        ])->willReturn(null);

        $dimensionContent = $this->createDraftDimensionContent(7, 'en');

        $resolver = $this->prophesize(RequestWorkflowResolverInterface::class);
        $resolver->resolveForContent($dimensionContent)->willReturn(null);

        $factory = $this->prophesize(WorkflowTransitionRequestViewFactoryInterface::class);
        $factory->build(Argument::cetera())->shouldNotBeCalled();

        $normalizer = new WorkflowTransitionRequestNormalizer(
            $repository->reveal(),
            $resolver->reveal(),
            $factory->reveal(),
        );
        $result = $normalizer->enhance($dimensionContent, ['title' => 'Hello']);

        $this->assertNull($result['activeWorkflowTransitionRequest']);
        $this->assertFalse($result['workflowTransitionRequestEnabled']);
        $this->assertFalse($result['_locked']);
        $this->assertNull($result['_lockReason']);
    }

    public function testEnhanceDelegatesActiveRequestToViewFactory(): void
    {
        $workflowTransitionRequest = new WorkflowTransitionRequest(Example::RESOURCE_KEY, '7', 'en');
        $workflowTransitionRequest->setCreator($this->prophesize(UserInterface::class)->reveal());

        $repository = $this->prophesize(WorkflowTransitionRequestRepositoryInterface::class);
        $repository->findOneBy([
            'resourceKey' => Example::RESOURCE_KEY,
            'resourceId' => '7',
            'locale' => 'en',
            'active' => true,
        ])->willReturn($workflowTransitionRequest);

        $dimensionContent = $this->createDraftDimensionContent(7, 'en');

        $resolver = $this->prophesize(RequestWorkflowResolverInterface::class);
        $resolver->resolveForContent($dimensionContent)->willReturn(new RequestWorkflow('default', null, []));

        $view = ['id' => $workflowTransitionRequest->getId(), 'status' => 'pending', 'approvalProgress' => ['required' => 1]];
        $factory = $this->prophesize(WorkflowTransitionRequestViewFactoryInterface::class);
        $factory->build($workflowTransitionRequest, $dimensionContent)->willReturn($view);

        $normalizer = new WorkflowTransitionRequestNormalizer(
            $repository->reveal(),
            $resolver->reveal(),
            $factory->reveal(),
        );
        $result = $normalizer->enhance($dimensionContent, ['title' => 'Hello']);

        $this->assertSame($view, $result['activeWorkflowTransitionRequest']);
        $this->assertTrue($result['workflowTransitionRequestEnabled']);
        $this->assertTrue($result['_locked']);
        $this->assertSame('workflow_transition_request', $result['_lockReason']);
    }

    private function createDraftDimensionContent(int $resourceId, string $locale): ExampleDimensionContent
    {
        $example = new Example();
        $reflection = new \ReflectionClass($example);
        $property = $reflection->getProperty('id');
        $property->setValue($example, $resourceId);

        $dimensionContent = new ExampleDimensionContent($example);
        $dimensionContent->setLocale($locale);
        $dimensionContent->setStage(DimensionContentInterface::STAGE_DRAFT);

        return $dimensionContent;
    }
}
