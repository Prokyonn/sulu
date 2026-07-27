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

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowResolverInterface;
use Sulu\Content\Domain\Exception\DuplicateActiveWorkflowTransitionRequestException;
use Sulu\Content\Domain\Exception\MissingAuthenticatedUserException;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * @final
 *
 * @internal this class is internal and should not be extended from or used in another context
 */
class WorkflowTransitionRequestTransitionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RequestWorkflowResolverInterface $requestWorkflowResolver,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function onRequestForReview(TransitionEvent $transitionEvent): void
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

        $workflow = $this->requestWorkflowResolver->resolveForContent($dimensionContent);
        if (null === $workflow) {
            // No workflow covers this content, so the review transition carries no request.
            return;
        }

        if (null !== $this->workflowTransitionRequestRepository->findOneBy([
            'resourceKey' => $resourceKey,
            'resourceId' => $resourceId,
            'locale' => $locale,
            'active' => true,
        ])) {
            throw new DuplicateActiveWorkflowTransitionRequestException($resourceKey, $resourceId, $locale);
        }

        $workflowTransitionRequest = new WorkflowTransitionRequest($resourceKey, $resourceId, $locale, $workflow->name);
        $workflowTransitionRequest->setRequiredApprovalCount($workflow->getRequiredApprovalCount());
        $workflowTransitionRequest->setCreator($this->resolveUser());
        $this->workflowTransitionRequestRepository->add($workflowTransitionRequest);

        try {
            // Flush eagerly so a race between the findOneBy pre-check above and a concurrent insert
            // surfaces as the typed domain exception rather than a generic 500 at the end of the request.
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new DuplicateActiveWorkflowTransitionRequestException($resourceKey, $resourceId, $locale, $e);
        }
    }

    private function resolveUser(): UserInterface
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            throw new MissingAuthenticatedUserException('create a workflow transition request');
        }

        return $user;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.content_workflow.transition.' . WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW => 'onRequestForReview',
            'workflow.content_workflow.transition.' . WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT => 'onRequestForReview',
        ];
    }
}
