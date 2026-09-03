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
use Sulu\Content\Application\Message\ValidateWorkflowTransitionRequestMessage;
use Sulu\Content\Application\RequestWorkflow\Prevalidator\PrevalidationContext;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflow;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowResolverInterface;
use Sulu\Content\Domain\Exception\DuplicateActiveWorkflowTransitionRequestException;
use Sulu\Content\Domain\Exception\MissingAuthenticatedUserException;
use Sulu\Content\Domain\Exception\NoRequestWorkflowException;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestPrevalidationFailedException;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
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
        /** @var string $locale */
        $locale = $dimensionContent->getLocale();

        $workflow = $this->requestWorkflowResolver->resolveForContent($dimensionContent);
        if (null === $workflow) {
            throw new NoRequestWorkflowException($resourceKey, $resourceId, $locale);
        }

        $this->assertMayEnterReview($workflow, $dimensionContent);

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

        // One pending reviewer row per configured validator, so the overlay can show which checks are
        // still outstanding and the handler has a row to claim.
        foreach (\array_keys($workflow->validators) as $validatorKey) {
            $workflowTransitionRequest->addValidator($validatorKey);
        }

        $this->workflowTransitionRequestRepository->add($workflowTransitionRequest);

        // No flush here: the request row and the workflow marking, which Symfony writes only after
        // these events return, have to reach the database in the same transaction. Inside a bus
        // dispatch DispatchAfterCurrentBusStamp holds the message until the flush middleware ran; a
        // direct ContentManager::applyTransition() has no such flush, so its caller has to flush.
        $this->messageBus->dispatch(new Envelope(
            new ValidateWorkflowTransitionRequestMessage($workflowTransitionRequest->getId()),
            [new DispatchAfterCurrentBusStamp()],
        ));
    }

    /**
     * Prevalidators gate entry into review: a failure aborts the transition before any request
     * exists, so the content stays editable and the author sees everything to fix at once.
     *
     * @template T of \Sulu\Content\Domain\Model\ContentRichEntityInterface
     *
     * @param DimensionContentInterface<T> $dimensionContent
     *
     * @throws WorkflowTransitionRequestPrevalidationFailedException
     */
    private function assertMayEnterReview(RequestWorkflow $workflow, DimensionContentInterface $dimensionContent): void
    {
        $messages = [];
        foreach ($workflow->prevalidators as $entry) {
            $context = new PrevalidationContext($dimensionContent, $entry['config'], $workflow->name);
            foreach ($entry['prevalidator']->check($context) as $failure) {
                $messages[] = $this->translator->trans($failure->messageKey, $failure->getTranslationParameters(), 'admin');
            }
        }

        if ([] === $messages) {
            return;
        }

        throw new WorkflowTransitionRequestPrevalidationFailedException(\implode(' ', $messages));
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
