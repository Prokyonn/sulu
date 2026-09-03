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
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * Closes the active workflow transition request whenever a transition takes the content out of
 * review: `cancel_review` / `cancel_review_draft` for the author, `reject` / `reject_draft` for the
 * reviewer. The transition itself moves the dimension content's `workflowPlace` back to
 * `unpublished`/`draft` so a new request can be submitted.
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
        $activeRequest = $this->findActiveRequest($transitionEvent);
        if (null === $activeRequest) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            throw new MissingAuthenticatedUserException('cancel a workflow transition request');
        }

        $activeRequest->cancelByUser($user);
    }

    /**
     * Rejecting is the reviewer's answer to someone else's request, so it closes the request without
     * the creator check that guards a cancel.
     */
    public function onReject(TransitionEvent $transitionEvent): void
    {
        $this->findActiveRequest($transitionEvent)?->cancel();
    }

    private function findActiveRequest(TransitionEvent $transitionEvent): ?WorkflowTransitionRequest
    {
        $dimensionContent = $transitionEvent->getSubject();
        if (!$dimensionContent instanceof DimensionContentInterface) {
            return null;
        }

        /** @var string $locale */
        $locale = $dimensionContent->getLocale();

        return $this->workflowTransitionRequestRepository->findOneBy([
            'resourceKey' => $dimensionContent::getResourceKey(),
            'resourceId' => (string) $dimensionContent->getResource()->getId(),
            'locale' => $locale,
            'active' => true,
        ]);
    }

    public static function getSubscribedEvents(): array
    {
        $prefix = 'workflow.content_workflow.transition.';

        return [
            $prefix . WorkflowInterface::WORKFLOW_TRANSITION_CANCEL_REVIEW => 'onCancelReview',
            $prefix . WorkflowInterface::WORKFLOW_TRANSITION_CANCEL_REVIEW_DRAFT => 'onCancelReview',
            $prefix . WorkflowInterface::WORKFLOW_TRANSITION_REJECT => 'onReject',
            $prefix . WorkflowInterface::WORKFLOW_TRANSITION_REJECT_DRAFT => 'onReject',
        ];
    }
}
