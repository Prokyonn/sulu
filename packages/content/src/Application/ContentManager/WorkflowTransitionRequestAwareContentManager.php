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

namespace Sulu\Content\Application\ContentManager;

use Sulu\Content\Domain\Exception\WorkflowTransitionRequestInProgressException;
use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;

/**
 * @internal this class is internal and should not be extended from or used in another context
 */
final class WorkflowTransitionRequestAwareContentManager implements ContentManagerInterface
{
    public function __construct(
        private readonly ContentManagerInterface $inner,
        private readonly WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository,
    ) {
    }

    public function resolve(ContentRichEntityInterface $contentRichEntity, array $dimensionAttributes): DimensionContentInterface
    {
        return $this->inner->resolve($contentRichEntity, $dimensionAttributes);
    }

    public function persist(ContentRichEntityInterface $contentRichEntity, array $data, array $dimensionAttributes): DimensionContentInterface
    {
        $this->assertNoActiveRequest($contentRichEntity, $dimensionAttributes);

        return $this->inner->persist($contentRichEntity, $data, $dimensionAttributes);
    }

    public function normalize(DimensionContentInterface $dimensionContent): array
    {
        return $this->inner->normalize($dimensionContent);
    }

    public function copy(
        ContentRichEntityInterface $sourceContentRichEntity,
        array $sourceDimensionAttributes,
        ContentRichEntityInterface $targetContentRichEntity,
        array $targetDimensionAttributes,
        array $options = [],
    ): DimensionContentInterface {
        $this->assertNoActiveRequest($targetContentRichEntity, $targetDimensionAttributes);

        return $this->inner->copy(
            $sourceContentRichEntity,
            $sourceDimensionAttributes,
            $targetContentRichEntity,
            $targetDimensionAttributes,
            $options,
        );
    }

    public function applyTransition(
        ContentRichEntityInterface $contentRichEntity,
        array $dimensionAttributes,
        string $transitionName,
        array $context = [],
    ): DimensionContentInterface {
        return $this->inner->applyTransition($contentRichEntity, $dimensionAttributes, $transitionName, $context);
    }

    /**
     * @template T of DimensionContentInterface
     *
     * @param ContentRichEntityInterface<T> $contentRichEntity
     * @param mixed[] $dimensionAttributes
     */
    private function assertNoActiveRequest(ContentRichEntityInterface $contentRichEntity, array $dimensionAttributes): void
    {
        $stage = $dimensionAttributes['stage'] ?? DimensionContentInterface::STAGE_DRAFT;
        if (DimensionContentInterface::STAGE_DRAFT !== $stage) {
            return;
        }

        $locale = $dimensionAttributes['locale'] ?? null;
        if (!\is_string($locale) || '' === $locale) {
            return;
        }

        try {
            $id = $contentRichEntity->getId();
        } catch (\TypeError) {
            // Transient entity: getId() reads an uninitialized typed property. No row → no active request possible.
            return;
        }

        /** @var class-string<T> $dimensionContentClass */
        $dimensionContentClass = \get_class($contentRichEntity->createDimensionContent());
        $resourceKey = $dimensionContentClass::getResourceKey();
        $resourceId = (string) $id;

        $activeRequest = $this->workflowTransitionRequestRepository->findOneBy([
            'resourceKey' => $resourceKey,
            'resourceId' => $resourceId,
            'locale' => $locale,
            'active' => true,
        ]);

        if (null !== $activeRequest) {
            throw new WorkflowTransitionRequestInProgressException($resourceKey, $resourceId, $locale);
        }
    }
}
