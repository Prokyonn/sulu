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

use Sulu\Content\Domain\Exception\ContentInReviewException;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;

/**
 * Answers the one question the admin controllers need before saving: may this request write content?
 *
 * "Locked" is the same condition the normalizer reports as `_locked`, so the rule the API enforces
 * and the read-only form the user sees can never disagree.
 *
 * @internal this class is internal and should not be extended from or used in another context
 */
final class ContentReviewLock implements ContentReviewLockInterface
{
    /**
     * Transitions that end a review. They are the only actions allowed to reach a locked resource,
     * and they never carry content changes because the form is read-only while the review runs.
     */
    private const REVIEW_RESOLVING_TRANSITIONS = [
        WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
        WorkflowInterface::WORKFLOW_TRANSITION_BYPASS_PUBLISH,
        WorkflowInterface::WORKFLOW_TRANSITION_REJECT,
        WorkflowInterface::WORKFLOW_TRANSITION_REJECT_DRAFT,
        WorkflowInterface::WORKFLOW_TRANSITION_CANCEL_REVIEW,
        WorkflowInterface::WORKFLOW_TRANSITION_CANCEL_REVIEW_DRAFT,
    ];

    public function __construct(
        private readonly WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository,
    ) {
    }

    public function shouldPersistContent(string $resourceKey, string $resourceId, string $locale, ?string $action): bool
    {
        if (!$this->isInReview($resourceKey, $resourceId, $locale)) {
            return true;
        }

        if (\in_array($action, self::REVIEW_RESOLVING_TRANSITIONS, true)) {
            return false;
        }

        throw new ContentInReviewException($resourceKey, $resourceId, $locale);
    }

    public function assertNotInReview(string $resourceKey, string $resourceId, string $locale): void
    {
        if ($this->isInReview($resourceKey, $resourceId, $locale)) {
            throw new ContentInReviewException($resourceKey, $resourceId, $locale);
        }
    }

    private function isInReview(string $resourceKey, string $resourceId, string $locale): bool
    {
        return null !== $this->workflowTransitionRequestRepository->findOneBy([
            'resourceKey' => $resourceKey,
            'resourceId' => $resourceId,
            'locale' => $locale,
            'active' => true,
        ]);
    }
}
