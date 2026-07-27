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

use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Content\Domain\Exception\MissingAuthenticatedUserException;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * When a `cancel_review` / `cancel_review_draft` workflow transition fires, mark the associated
 * active workflow transition request as cancelled. The transition itself moves the dimension
 * content's `workflowPlace` back to `unpublished`/`draft` so the user can submit a new request.
 *
 * This is the only way to cancel a request: it keeps the request row and the content's workflow
 * place in step, which a request-only cancel could not.
 *
 * @final
 *
 * @internal this class is internal and should not be extended from or used in another context
 */
class WorkflowTransitionRequestCancelTransitionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function onCancelReview(TransitionEvent $transitionEvent): void
    {
        $dimensionContent = $transitionEvent->getSubject();
        if (!$dimensionContent instanceof DimensionContentInterface) {
            return;
        }

        $resourceKey = $dimensionContent::getResourceKey();
        $resourceId = (string) $dimensionContent->getResource()->getId();
        $locale = $dimensionContent->getLocale();
        if (null === $locale || '' === $locale) {
            return;
        }

        $activeRequest = $this->workflowTransitionRequestRepository->findOneBy([
            'resourceKey' => $resourceKey,
            'resourceId' => $resourceId,
            'locale' => $locale,
            'active' => true,
        ]);

        if (null === $activeRequest) {
            return;
        }

        // Only the requester may withdraw their own request, matching the rule the domain model
        // enforces for every other decision on a request.
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            throw new MissingAuthenticatedUserException('cancel a workflow transition request');
        }

        $activeRequest->cancelByUser($user);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.content_workflow.transition.' . WorkflowInterface::WORKFLOW_TRANSITION_CANCEL_REVIEW => 'onCancelReview',
            'workflow.content_workflow.transition.' . WorkflowInterface::WORKFLOW_TRANSITION_CANCEL_REVIEW_DRAFT => 'onCancelReview',
        ];
    }
}
