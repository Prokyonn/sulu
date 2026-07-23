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

use Sulu\Content\Application\RequestWorkflow\Validator\ValidationResult;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidatorOutcome;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;

interface RequestWorkflowEvaluatorInterface
{
    /**
     * Run all validators of the workflow attached to the request and return the aggregated result.
     *
     * @template T of \Sulu\Content\Domain\Model\ContentRichEntityInterface
     *
     * @param DimensionContentInterface<T>|null $dimensionContent
     */
    public function evaluate(
        WorkflowTransitionRequest $request,
        ?DimensionContentInterface $dimensionContent = null,
    ): ValidationResult;

    /**
     * Run all validators and return one {@see ValidatorOutcome} per validator (passed + failed).
     * Use this when consumers need to display the full validator checklist (e.g. the review
     * overlay), as opposed to just the failures returned by {@see self::evaluate()}.
     *
     * @template T of \Sulu\Content\Domain\Model\ContentRichEntityInterface
     *
     * @param DimensionContentInterface<T>|null $dimensionContent
     *
     * @return list<ValidatorOutcome>
     */
    public function evaluateOutcomes(
        WorkflowTransitionRequest $request,
        ?DimensionContentInterface $dimensionContent = null,
    ): array;

    /**
     * Re-evaluate the request and persist a derived status (PENDING / APPROVED / REJECTED) on the entity.
     * Terminal statuses (CANCELLED / PUBLISHED) are never overwritten.
     *
     * @template T of \Sulu\Content\Domain\Model\ContentRichEntityInterface
     *
     * @param DimensionContentInterface<T>|null $dimensionContent
     */
    public function refreshStatus(
        WorkflowTransitionRequest $request,
        ?DimensionContentInterface $dimensionContent = null,
    ): void;
}
