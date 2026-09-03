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

namespace Sulu\Content\Application\ContentPersister;

use Sulu\Content\Application\ContentMerger\ContentMergerInterface;
use Sulu\Content\Application\ContentWorkflow\ContentWorkflowInterface;
use Sulu\Content\Domain\Factory\DimensionContentCollectionFactoryInterface;
use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentCollectionInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;

class ContentPersister implements ContentPersisterInterface
{
    public function __construct(
        private DimensionContentCollectionFactoryInterface $dimensionContentCollectionFactory,
        private ContentMergerInterface $contentMerger,
        private ContentWorkflowInterface $contentWorkflow
    ) {
    }

    public function persist(ContentRichEntityInterface $contentRichEntity, array $data, array $dimensionAttributes): DimensionContentInterface
    {
        /*
         * Data should always be persisted to the STAGE_DRAFT content-dimension of the given $dimensionAttributes.
         * Modifying data of other content-dimensions (eg. STAGE_LIVE) should only be possible by applying transitions
         * of the ContentWorkflow.
         *
         * TODO: maybe throw an exception here if the $dimensionAttributes contain another stage than 'STAGE_DRAFT'
         */

        $dimensionContentCollection = $this->dimensionContentCollectionFactory->create(
            $contentRichEntity,
            $dimensionAttributes,
            $data
        );

        $this->applyEditTransition($contentRichEntity, $dimensionContentCollection, $dimensionAttributes);

        return $this->contentMerger->merge($dimensionContentCollection);
    }

    /**
     * Saving content moves it out of its current place, published content gains a draft, and content
     * under review has no `edit` transition at all, so the workflow itself rejects the write. Applying
     * this here rather than in the data mapper keeps mapping-only callers (the preview) from moving
     * content, and makes the rule an invariant of every persist rather than of the admin controllers.
     *
     * @template T of DimensionContentInterface
     *
     * @param ContentRichEntityInterface<T> $contentRichEntity
     * @param DimensionContentCollectionInterface<T> $dimensionContentCollection
     * @param mixed[] $dimensionAttributes
     */
    private function applyEditTransition(
        ContentRichEntityInterface $contentRichEntity,
        DimensionContentCollectionInterface $dimensionContentCollection,
        array $dimensionAttributes
    ): void {
        $dimensionContent = $dimensionContentCollection->getDimensionContent(
            $dimensionContentCollection->getDimensionAttributes()
        );

        if (!$dimensionContent instanceof WorkflowInterface) {
            return;
        }

        if (DimensionContentInterface::STAGE_LIVE === $dimensionContent->getStage()) {
            return;
        }

        // No place yet means nothing to transition from. Unpublished content stays unpublished when
        // saved. Every other place, including the review places, which have no `edit` transition at
        // all, goes through the workflow, so it decides whether this write is allowed.
        $workflowPlace = $dimensionContent->getWorkflowPlace();
        if (null === $workflowPlace || WorkflowInterface::WORKFLOW_PLACE_UNPUBLISHED === $workflowPlace) {
            return;
        }

        $this->contentWorkflow->apply(
            $contentRichEntity,
            $dimensionContent::getEffectiveDimensionAttributes(['locale' => $dimensionContent->getLocale()]),
            $dimensionContent::getWorkflowTransitionEdit()
        );
    }
}
