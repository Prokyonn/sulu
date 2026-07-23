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

namespace Sulu\Content\Application\WorkflowTransitionRequest;

use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowEvaluatorInterface;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowRegistryInterface;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationFailure;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewer;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewerStatusEnum;

/**
 * @internal
 */
final class WorkflowTransitionRequestViewFactory implements WorkflowTransitionRequestViewFactoryInterface
{
    public function __construct(
        private readonly RequestWorkflowEvaluatorInterface $requestWorkflowEvaluator,
        private readonly RequestWorkflowRegistryInterface $requestWorkflowRegistry,
    ) {
    }

    public function build(WorkflowTransitionRequest $request, ?DimensionContentInterface $dimensionContent = null): array
    {
        return [
            'id' => $request->getId(),
            'resourceKey' => $request->getResourceKey(),
            'resourceId' => $request->getResourceId(),
            'locale' => $request->getLocale(),
            'status' => $request->getStatus()->value,
            'workflowName' => $request->getWorkflowName(),
            'requestedAt' => $request->getRequestedAt()->format(\DATE_ATOM),
            'createdBy' => $this->serializeUser($request->getCreator()),
            'approvalProgress' => $this->buildApprovalProgress($request),
            'reviewers' => \array_map(
                fn (WorkflowTransitionRequestReviewer $reviewer) => $this->serializeReviewer($reviewer),
                $request->getReviewers(),
            ),
            'publishValidation' => null === $dimensionContent
                ? null
                : $this->buildPublishValidation($request, $dimensionContent),
        ];
    }

    /**
     * @return array{required: int, approved: int, rejected: int, remainingApprovals: int}
     */
    private function buildApprovalProgress(WorkflowTransitionRequest $request): array
    {
        $required = $request->getRequiredApprovalCount() ?? $this->resolveRequiredFromRegistry($request);

        $approved = 0;
        $rejected = 0;
        foreach ($request->getReviewers() as $reviewer) {
            if (WorkflowTransitionRequestReviewerStatusEnum::APPROVED === $reviewer->getStatus()) {
                ++$approved;
            } else {
                ++$rejected;
            }
        }

        return [
            'required' => $required,
            'approved' => $approved,
            'rejected' => $rejected,
            'remainingApprovals' => \max(0, $required - $approved),
        ];
    }

    private function resolveRequiredFromRegistry(WorkflowTransitionRequest $request): int
    {
        $workflowName = $request->getWorkflowName();

        return $this->requestWorkflowRegistry->has($workflowName)
            ? $this->requestWorkflowRegistry->get($workflowName)->getRequiredApprovalCount()
            : 0;
    }

    /**
     * @template T of \Sulu\Content\Domain\Model\ContentRichEntityInterface
     *
     * @param DimensionContentInterface<T> $dimensionContent
     *
     * @return array<string, mixed>
     */
    private function buildPublishValidation(WorkflowTransitionRequest $request, DimensionContentInterface $dimensionContent): array
    {
        // Evaluate the publish-guard validators against the current dimension content so the UI can warn
        // *before* the user clicks Publish. A request can be APPROVED by reviewers while a content-dependent
        // validator (SEO, excerpt) would still fail at publish time, because reviewer actions run the
        // evaluator without dimension content.
        $outcomes = $this->requestWorkflowEvaluator->evaluateOutcomes($request, $dimensionContent);
        $allFailures = [];
        $hasPending = false;
        foreach ($outcomes as $outcome) {
            if ($outcome->pending) {
                $hasPending = true;
                continue;
            }
            if (!$outcome->passed) {
                $allFailures = \array_merge($allFailures, $outcome->failures);
            }
        }

        $serializeFailure = static fn (ValidationFailure $failure) => [
            'validatorKey' => $failure->validatorKey,
            'messageKey' => $failure->messageKey,
            'messageParameters' => $failure->messageParameters,
            'details' => $failure->details,
        ];

        return [
            'passed' => !$hasPending && [] === $allFailures,
            'pending' => $hasPending,
            'outcomes' => \array_map(
                static fn ($outcome) => [
                    'validatorKey' => $outcome->validatorKey,
                    'passed' => $outcome->passed,
                    'pending' => $outcome->pending,
                    'failures' => \array_map($serializeFailure, $outcome->failures),
                ],
                $outcomes,
            ),
            'failures' => \array_map($serializeFailure, $allFailures),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeReviewer(WorkflowTransitionRequestReviewer $reviewer): array
    {
        return [
            'id' => $reviewer->getId(),
            'status' => $reviewer->getStatus()->value,
            'comment' => $reviewer->getComment(),
            'reviewer' => $this->serializeUser($reviewer->getCreator()),
            'decidedAt' => $reviewer->getChanged()->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array{id: int|string|null, fullName: string}|null
     */
    private function serializeUser(?UserInterface $user): ?array
    {
        if (null === $user) {
            return null;
        }

        return [
            'id' => $user->getId(),
            'fullName' => $user->getFullName(),
        ];
    }
}
