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

namespace Sulu\Content\Infrastructure\Doctrine\EventListener;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;

/**
 * Doctrine listener that purges workflow transition requests whenever the resource they reference is deleted.
 *
 * Full entity removal (a `ContentRichEntityInterface` aggregate) deletes every workflow transition request for that
 * resource. Removal of a single dimension (`DimensionContentInterface`) only deletes workflow transition requests for
 * the matching locale. This avoids dangling rows without requiring consumer bundles to wire any glue code.
 *
 * @internal
 */
final class CascadeDeleteWorkflowTransitionRequestListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        /** @var array<int, array{resourceKey: string, resourceId: string, locale?: string}> $purges */
        $purges = [];
        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            $filter = $this->buildFilter($entity);
            if (null !== $filter) {
                $purges[] = $filter;
            }
        }

        if ([] === $purges) {
            return;
        }

        $repository = $entityManager->getRepository(WorkflowTransitionRequest::class);

        foreach ($purges as $filter) {
            foreach ($repository->findBy($filter) as $workflowTransitionRequest) {
                $entityManager->remove($workflowTransitionRequest);
            }
        }
    }

    /**
     * @return array{resourceKey: string, resourceId: string, locale?: string}|null
     */
    private function buildFilter(object $entity): ?array
    {
        if ($entity instanceof DimensionContentInterface) {
            $locale = $entity->getLocale();
            if (null === $locale) {
                return null;
            }

            return [
                'resourceKey' => $entity::getResourceKey(),
                'resourceId' => (string) $entity->getResource()->getId(),
                'locale' => $locale,
            ];
        }

        if ($entity instanceof ContentRichEntityInterface) {
            return [
                'resourceKey' => $this->resolveResourceKey($entity),
                'resourceId' => (string) $entity->getId(),
            ];
        }

        return null;
    }

    /**
     * The dimension content class advertises its resource key statically, so we can look it up via
     * the class name without instantiating a transient `DimensionContentInterface` (and risking the
     * factory's side effects).
     *
     * @template T of DimensionContentInterface
     *
     * @param ContentRichEntityInterface<T> $entity
     */
    private function resolveResourceKey(ContentRichEntityInterface $entity): string
    {
        $dimensionContentClass = \get_class($entity->createDimensionContent());

        /** @var class-string<T> $dimensionContentClass */
        return $dimensionContentClass::getResourceKey();
    }
}
