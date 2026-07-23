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

use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * @final
 *
 * @internal this class is internal and should not be extended from or used in another context
 */
class WorkflowTransitionRequestPublishTransitionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository,
    ) {
    }

    public function onPublish(TransitionEvent $transitionEvent): void
    {
        $dimensionContent = $transitionEvent->getSubject();

        if (!$dimensionContent instanceof DimensionContentInterface) {
            return;
        }

        // Reuse the request resolved by the guard subscriber when present (avoids a second SELECT for
        // the common case where both subscribers are active).
        $workflowTransitionRequest = WorkflowTransitionRequestPublishGuardSubscriber::readWorkflowTransitionRequest($transitionEvent);

        if (null === $workflowTransitionRequest) {
            $locale = $dimensionContent->getLocale();
            if (null === $locale || '' === $locale) {
                return;
            }

            $workflowTransitionRequest = $this->workflowTransitionRequestRepository->findOneBy([
                'resourceKey' => $dimensionContent::getResourceKey(),
                'resourceId' => (string) $dimensionContent->getResource()->getId(),
                'locale' => $locale,
                'active' => true,
            ]);
        }

        $workflowTransitionRequest?->publish();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.content_workflow.transition.' . WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH => ['onPublish', -100],
        ];
    }
}
