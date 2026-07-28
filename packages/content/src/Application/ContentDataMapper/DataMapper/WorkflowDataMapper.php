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

namespace Sulu\Content\Application\ContentDataMapper\DataMapper;

use Sulu\Content\Application\ContentWorkflow\ContentWorkflowInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;

class WorkflowDataMapper implements DataMapperInterface
{
    public function __construct(private ContentWorkflowInterface $contentWorkflow)
    {
    }

    public function map(
        DimensionContentInterface $unlocalizedDimensionContent,
        DimensionContentInterface $localizedDimensionContent,
        array $data
    ): void {
        if (!$localizedDimensionContent instanceof WorkflowInterface) {
            return;
        }

        $this->setWorkflowData($localizedDimensionContent, $data);
    }

    /**
     * @template T of DimensionContentInterface
     *
     * @param WorkflowInterface&T $object
     * @param mixed[] $data
     */
    private function setWorkflowData(WorkflowInterface $object, array $data): void
    {
        $this->setInitialPlaceToDraftDimension($object, $data);
        $this->setPublishedToLiveDimension($object, $data);
    }

    /**
     * @template T of DimensionContentInterface
     *
     * @param WorkflowInterface&T $object
     * @param mixed[] $data
     */
    private function setInitialPlaceToDraftDimension(WorkflowInterface $object, array $data): void
    {
        // we want to set the initial place only to the draft dimension, the live dimension should not have a place
        // after the place was set by this mapper initially, the place should only be changed by the ContentWorkflow
        // see: https://github.com/sulu/SuluContentBundle/issues/92

        if (DimensionContentInterface::STAGE_DRAFT !== $object->getStage()) {
            return;
        }

        if (!$object->getWorkflowPlace()) {
            $object->setWorkflowPlace($object::getWorkflowInitialPlace());
        }
    }

    /**
     * @template T of DimensionContentInterface
     *
     * @param WorkflowInterface&T $object
     * @param mixed[] $data
     */
    private function setPublishedToLiveDimension(WorkflowInterface $object, array $data): void
    {
        // the published property of the draft dimension should only be changed by a ContentWorkflow subscriber
        // therefore we only want to copy the published property from the draft to the live dimension

        if (DimensionContentInterface::STAGE_LIVE !== $object->getStage()) {
            if (WorkflowInterface::WORKFLOW_PLACE_UNPUBLISHED !== $object->getWorkflowPlace()
                && !$this->isInReview($object)
            ) {
                $this->contentWorkflow->apply(
                    $object->getResource(),
                    $object::getEffectiveDimensionAttributes(['locale' => $object->getLocale()]),
                    $object::getWorkflowTransitionEdit()
                );
            }

            return;
        }

        /** @var string|null $published */
        $published = $data['published'] ?? null;

        if (!$published) {
            throw new \RuntimeException('Expected "published" to be set in the data array.');
        }

        $object->setWorkflowPublished(new \DateTimeImmutable($published));
    }

    /**
     * Content under review keeps its place: the workflow defines no `edit` transition out of
     * `review`/`review_draft`, and the transition that ends the review will set the next place
     * itself. Persists that reach this point during a review carry no content change — either a
     * review-resolving transition or a preview render. Rejecting a genuine edit is the job of
     * {@see \Sulu\Content\Application\WorkflowTransitionRequest\ContentReviewLockInterface},
     * which runs where the caller's intent is still known.
     *
     * @template T of DimensionContentInterface
     *
     * @param WorkflowInterface&T $object
     */
    private function isInReview(WorkflowInterface $object): bool
    {
        return \in_array($object->getWorkflowPlace(), [
            WorkflowInterface::WORKFLOW_PLACE_REVIEW,
            WorkflowInterface::WORKFLOW_PLACE_REVIEW_DRAFT,
        ], true);
    }
}
