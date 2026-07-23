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

namespace Sulu\Content\Application\ContentNormalizer\Normalizer;

use Sulu\Content\Application\RequestWorkflow\RequestWorkflowResolverInterface;
use Sulu\Content\Application\WorkflowTransitionRequest\WorkflowTransitionRequestViewFactoryInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;

/**
 * Augments the normalized representation of a draft `DimensionContentInterface` with information about the
 * currently active `WorkflowTransitionRequest`. The UI renders the publish-toolbar dropdown items based on this
 * data; clients without an active request do not see the field. The request payload itself is built by the
 * shared {@see WorkflowTransitionRequestViewFactoryInterface} so it stays identical to the by-id controller.
 *
 * @internal
 */
class WorkflowTransitionRequestNormalizer implements NormalizerInterface
{
    public function __construct(
        private readonly WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository,
        private readonly RequestWorkflowResolverInterface $requestWorkflowResolver,
        private readonly WorkflowTransitionRequestViewFactoryInterface $viewFactory,
    ) {
    }

    public function getIgnoredAttributes(object $object): array
    {
        return [];
    }

    public function enhance(object $object, array $normalizedData): array
    {
        if (!$object instanceof DimensionContentInterface) {
            return $normalizedData;
        }

        if (DimensionContentInterface::STAGE_DRAFT !== $object->getStage()) {
            return $normalizedData;
        }

        $locale = $object->getLocale();
        if (null === $locale || '' === $locale) {
            return $normalizedData;
        }

        $resource = $object->getResource();

        try {
            $resourceId = (string) $resource->getId();
        } catch (\TypeError) {
            return $normalizedData;
        }

        $request = $this->workflowTransitionRequestRepository->findOneBy([
            'resourceKey' => $object::getResourceKey(),
            'resourceId' => $resourceId,
            'locale' => $locale,
            'active' => true,
        ]);

        $normalizedData['activeWorkflowTransitionRequest'] = null === $request
            ? null
            : $this->viewFactory->build($request, $object);

        // Signals to the toolbar whether this template participates in a request workflow at all. The
        // admin uses this to hide the direct "Save & publish"/"Publish" actions when a workflow exists,
        // forcing publishes through the request-for-review flow.
        $normalizedData['workflowTransitionRequestEnabled'] = null !== $this->requestWorkflowResolver->resolveForContent($object);

        // Frontend uses these to put the form into a readonly state and render the snackbar.
        // `_locked` is a generic channel — future lock sources (mandatory translations, …) reuse the key.
        $normalizedData['_locked'] = null !== $request;
        $normalizedData['_lockReason'] = null !== $request ? 'workflow_transition_request' : null;

        return $normalizedData;
    }
}
