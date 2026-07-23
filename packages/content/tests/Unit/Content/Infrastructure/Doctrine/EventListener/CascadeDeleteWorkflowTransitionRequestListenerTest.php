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

namespace Sulu\Content\Tests\Unit\Content\Infrastructure\Doctrine\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Infrastructure\Doctrine\EventListener\CascadeDeleteWorkflowTransitionRequestListener;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\ExampleDimensionContent;

#[CoversClass(CascadeDeleteWorkflowTransitionRequestListener::class)]
class CascadeDeleteWorkflowTransitionRequestListenerTest extends TestCase
{
    use ProphecyTrait;

    public function testNoOpWhenNoEntitiesScheduledForDeletion(): void
    {
        $unitOfWork = $this->prophesize(UnitOfWork::class);
        $unitOfWork->getScheduledEntityDeletions()->willReturn([]);

        $entityManager = $this->prophesize(EntityManagerInterface::class);
        $entityManager->getUnitOfWork()->willReturn($unitOfWork->reveal());
        $entityManager->getRepository(Argument::any())->shouldNotBeCalled();
        $entityManager->remove(Argument::any())->shouldNotBeCalled();

        $listener = new CascadeDeleteWorkflowTransitionRequestListener();
        $listener->onFlush(new OnFlushEventArgs($entityManager->reveal()));
    }

    public function testIgnoresUnrelatedDeletions(): void
    {
        $unitOfWork = $this->prophesize(UnitOfWork::class);
        $unitOfWork->getScheduledEntityDeletions()->willReturn([new \stdClass()]);

        $entityManager = $this->prophesize(EntityManagerInterface::class);
        $entityManager->getUnitOfWork()->willReturn($unitOfWork->reveal());
        $entityManager->getRepository(Argument::any())->shouldNotBeCalled();
        $entityManager->remove(Argument::any())->shouldNotBeCalled();

        $listener = new CascadeDeleteWorkflowTransitionRequestListener();
        $listener->onFlush(new OnFlushEventArgs($entityManager->reveal()));
    }

    public function testFullEntityRemovalLooksUpRequestsByResourceKeyAndId(): void
    {
        $example = $this->createExampleWithId(7);

        $existingRequest = new WorkflowTransitionRequest('examples', '7', 'en');

        $repository = $this->prophesize(EntityRepository::class);
        $repository->findBy(['resourceKey' => Example::RESOURCE_KEY, 'resourceId' => '7'])
            ->willReturn([$existingRequest])
            ->shouldBeCalled();

        $unitOfWork = $this->prophesize(UnitOfWork::class);
        $unitOfWork->getScheduledEntityDeletions()
            ->willReturn([$example])
            ->shouldBeCalled();

        $entityManager = $this->prophesize(EntityManagerInterface::class);
        $entityManager->getUnitOfWork()
            ->willReturn($unitOfWork->reveal())
            ->shouldBeCalled();
        $entityManager->getRepository(WorkflowTransitionRequest::class)
            ->willReturn($repository->reveal())
            ->shouldBeCalled();
        $entityManager->remove($existingRequest)
            ->shouldBeCalled();

        $listener = new CascadeDeleteWorkflowTransitionRequestListener();
        $listener->onFlush(new OnFlushEventArgs($entityManager->reveal()));
    }

    public function testDimensionContentRemovalLooksUpRequestsByLocale(): void
    {
        $example = $this->createExampleWithId(7);
        $dimensionContent = new ExampleDimensionContent($example);
        $dimensionContent->setLocale('en');

        $existingRequest = new WorkflowTransitionRequest('examples', '7', 'en');

        $repository = $this->prophesize(EntityRepository::class);
        $repository->findBy(['resourceKey' => Example::RESOURCE_KEY, 'resourceId' => '7', 'locale' => 'en'])
            ->willReturn([$existingRequest])
            ->shouldBeCalled();

        $unitOfWork = $this->prophesize(UnitOfWork::class);
        $unitOfWork->getScheduledEntityDeletions()
            ->willReturn([$dimensionContent])
            ->shouldBeCalled();

        $entityManager = $this->prophesize(EntityManagerInterface::class);
        $entityManager->getUnitOfWork()
            ->willReturn($unitOfWork->reveal())
            ->shouldBeCalled();
        $entityManager->getRepository(WorkflowTransitionRequest::class)
            ->willReturn($repository->reveal())
            ->shouldBeCalled();
        $entityManager->remove($existingRequest)
            ->shouldBeCalled();

        $listener = new CascadeDeleteWorkflowTransitionRequestListener();
        $listener->onFlush(new OnFlushEventArgs($entityManager->reveal()));
    }

    private function createExampleWithId(int $id): Example
    {
        $example = new Example();

        $reflection = new \ReflectionClass($example);
        $property = $reflection->getProperty('id');
        $property->setValue($example, $id);

        return $example;
    }
}
