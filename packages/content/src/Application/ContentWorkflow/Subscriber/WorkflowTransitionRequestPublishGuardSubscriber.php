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

use Sulu\Content\Application\ContentWorkflow\ContentWorkflowInterface;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowEvaluatorInterface;
use Sulu\Content\Application\Security\BypassReviewAuthorizerInterface;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestNotApprovedException;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * Blocks `publish` transitions unless the active workflow transition request passes its workflow's
 * validators against the current dimension content. Listens to the transition event (not the guard
 * event) so it can read the workflow context — Symfony 7 removed `GuardEvent::getContext()`.
 *
 * Callers can opt out by passing `['force' => true]` as workflow context (see
 * {@see ContentWorkflowInterface::FORCE_CONTEXT_KEY}). System-driven publishes such as
 * `sulu:page:initialize` and the "Bypass review and publish" admin action use this.
 *
 * The resolved request is stored back on the event context under {@see self::CONTEXT_KEY} so the
 * downstream {@see WorkflowTransitionRequestPublishTransitionSubscriber} can consume it without a
 * second SELECT.
 *
 * @final
 *
 * @internal this class is internal and should not be extended from or used in another context
 */
class WorkflowTransitionRequestPublishGuardSubscriber implements EventSubscriberInterface
{
    public const CONTEXT_KEY = '_workflowTransitionRequest';

    public function __construct(
        private readonly WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository,
        private readonly RequestWorkflowEvaluatorInterface $requestWorkflowEvaluator,
        private readonly BypassReviewAuthorizerInterface $bypassReviewAuthorizer,
    ) {
    }

    public function onPublishTransition(TransitionEvent $event): void
    {
        $dimensionContent = $event->getSubject();

        if (!$dimensionContent instanceof DimensionContentInterface) {
            return;
        }

        $resourceKey = $dimensionContent::getResourceKey();
        $resourceId = (string) $dimensionContent->getResource()->getId();

        $context = $event->getContext();

        if (true === ($context[ContentWorkflowInterface::FORCE_CONTEXT_KEY] ?? false)) {
            // Co-locate the bypass authorization with what it bypasses: any caller that sets
            // FORCE_CONTEXT_KEY must hold the LIVE permission for this resource, otherwise the
            // BypassReviewAuthorizer throws AccessDeniedException. This protects against future
            // guard subscribers being silently bypassed by callers that have force=true but no
            // explicit authorization check at the controller layer.
            $this->bypassReviewAuthorizer->assertCanBypass($resourceKey, $resourceId);

            return;
        }

        $locale = $dimensionContent->getLocale();
        if (null === $locale || '' === $locale) {
            return;
        }

        $request = $this->workflowTransitionRequestRepository->findOneBy([
            'resourceKey' => $resourceKey,
            'resourceId' => $resourceId,
            'locale' => $locale,
            'active' => true,
        ]);

        if (null === $request) {
            throw WorkflowTransitionRequestNotApprovedException::noRequest();
        }

        // Re-evaluate against the current dimension content so validators that depend on the
        // payload (SEO, excerpt) reflect any edits since the last reviewer decision.
        $result = $this->requestWorkflowEvaluator->evaluate($request, $dimensionContent);
        if (!$result->passed) {
            throw WorkflowTransitionRequestNotApprovedException::notApproved();
        }

        $context[self::CONTEXT_KEY] = $request;
        $event->setContext($context);
    }

    public static function readWorkflowTransitionRequest(TransitionEvent $event): ?WorkflowTransitionRequest
    {
        $stored = $event->getContext()[self::CONTEXT_KEY] ?? null;

        return $stored instanceof WorkflowTransitionRequest ? $stored : null;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.content_workflow.transition.' . WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH => ['onPublishTransition', 100],
        ];
    }
}
