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

namespace Sulu\Content\Application\RequestWorkflow;

use Sulu\Content\Application\RequestWorkflow\Validator\ValidationContext;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationResult;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidatorOutcome;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewerStatusEnum;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestStatusEnum;

final class RequestWorkflowEvaluator implements RequestWorkflowEvaluatorInterface
{
    public function __construct(
        private readonly RequestWorkflowRegistryInterface $registry,
    ) {
    }

    /**
     * @template T of \Sulu\Content\Domain\Model\ContentRichEntityInterface
     *
     * @param DimensionContentInterface<T>|null $dimensionContent
     */
    public function evaluate(
        WorkflowTransitionRequest $request,
        ?DimensionContentInterface $dimensionContent = null,
    ): ValidationResult {
        $failures = [];
        $hasPending = false;
        $hasFailure = false;
        foreach ($this->evaluateOutcomes($request, $dimensionContent) as $outcome) {
            if ($outcome->pending) {
                $hasPending = true;
                continue;
            }
            if (!$outcome->passed) {
                // Track the verdict separately from the messages: a validator may fail without
                // describing why, and that must not read as a pass.
                $hasFailure = true;
                $failures = \array_merge($failures, $outcome->failures);
            }
        }

        if ($hasPending) {
            return ValidationResult::pending();
        }

        return $hasFailure ? ValidationResult::fail(...$failures) : ValidationResult::pass();
    }

    public function evaluateOutcomes(
        WorkflowTransitionRequest $request,
        ?DimensionContentInterface $dimensionContent = null,
    ): array {
        $workflow = $this->registry->get($request->getWorkflowName());

        $outcomes = [];
        foreach ($workflow->validators as $entry) {
            $context = new ValidationContext($request, $entry['config'], $dimensionContent);
            $result = $entry['validator']->check($context);
            $outcomes[] = new ValidatorOutcome(
                $entry['validator']->getKey(),
                $result->passed,
                $result->pending,
                $result->failures,
            );
        }

        return $outcomes;
    }

    /**
     * Recomputes the persisted status after a reviewer decision. Callers that hold the dimension
     * content should pass it; the approve/reject handlers do not, so content-dependent validators
     * are skipped there. The publish guard re-evaluates with the content before publishing, which
     * is the authoritative check — content can change after a decision either way.
     *
     * @template T of \Sulu\Content\Domain\Model\ContentRichEntityInterface
     *
     * @param DimensionContentInterface<T>|null $dimensionContent
     */
    public function refreshStatus(
        WorkflowTransitionRequest $request,
        ?DimensionContentInterface $dimensionContent = null,
    ): void {
        if ($request->getStatus()->isClosed()) {
            return;
        }

        // Approval threshold wins: a met threshold approves the request even if a reviewer rejected
        // (rejection is non-blocking feedback). Only when the threshold is not yet met does a
        // rejection surface as REJECTED ("changes requested"), otherwise the request stays PENDING.
        $result = $this->evaluate($request, $dimensionContent);
        if ($result->passed) {
            $request->updateStatus(WorkflowTransitionRequestStatusEnum::APPROVED);

            return;
        }

        if ($this->hasRejection($request)) {
            $request->updateStatus(WorkflowTransitionRequestStatusEnum::REJECTED);

            return;
        }

        $request->updateStatus(WorkflowTransitionRequestStatusEnum::PENDING);
    }

    private function hasRejection(WorkflowTransitionRequest $request): bool
    {
        foreach ($request->getReviewers() as $reviewer) {
            if (WorkflowTransitionRequestReviewerStatusEnum::REJECTED === $reviewer->getStatus()) {
                return true;
            }
        }

        return false;
    }
}
