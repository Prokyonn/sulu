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

namespace Sulu\Content\Application\ContentWorkflow\Subscriber;

use Sulu\Content\Application\RequestWorkflow\RequestWorkflowResolverInterface;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestNotApprovedException;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestStatusEnum;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * Blocks `publish` unless the active workflow transition request is approved. It listens on the
 * transition event, not the guard event, because a blocked guard reports a generic "transition not
 * enabled" instead of the reason the review is not through.
 *
 * @final
 *
 * @internal this class is internal and should not be extended from or used in another context
 */
class WorkflowTransitionRequestPublishGuardSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository,
        private readonly RequestWorkflowResolverInterface $requestWorkflowResolver,
    ) {
    }

    public function onPublishTransition(TransitionEvent $event): void
    {
        $dimensionContent = $event->getSubject();

        if (!$dimensionContent instanceof DimensionContentInterface) {
            return;
        }

        if (null === $this->requestWorkflowResolver->resolveForContent($dimensionContent)) {
            return;
        }

        /** @var string $locale */
        $locale = $dimensionContent->getLocale();

        $request = $this->workflowTransitionRequestRepository->findOneBy([
            'resourceKey' => $dimensionContent::getResourceKey(),
            'resourceId' => (string) $dimensionContent->getResource()->getId(),
            'locale' => $locale,
            'active' => true,
        ]);

        if (null === $request) {
            throw WorkflowTransitionRequestNotApprovedException::noRequest();
        }

        if (WorkflowTransitionRequestStatusEnum::APPROVED !== $request->getStatus()) {
            throw WorkflowTransitionRequestNotApprovedException::notApproved();
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.content_workflow.transition.' . WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH => ['onPublishTransition', 100],
        ];
    }
}
