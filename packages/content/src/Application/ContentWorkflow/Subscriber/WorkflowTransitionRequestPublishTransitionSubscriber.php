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

        /** @var string $locale */
        $locale = $dimensionContent->getLocale();

        $workflowTransitionRequest = $this->workflowTransitionRequestRepository->findOneBy([
            'resourceKey' => $dimensionContent::getResourceKey(),
            'resourceId' => (string) $dimensionContent->getResource()->getId(),
            'locale' => $locale,
            'active' => true,
        ]);

        $workflowTransitionRequest?->publish();
    }

    public static function getSubscribedEvents(): array
    {
        $prefix = 'workflow.content_workflow.transition.';

        return [
            $prefix . WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH => ['onPublish', -100],
            $prefix . WorkflowInterface::WORKFLOW_TRANSITION_BYPASS_PUBLISH => ['onPublish', -100],
        ];
    }
}
