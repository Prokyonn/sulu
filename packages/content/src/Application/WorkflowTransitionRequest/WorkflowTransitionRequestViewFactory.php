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
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewer;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewerStatusEnum;

/**
 * @internal
 */
final class WorkflowTransitionRequestViewFactory implements WorkflowTransitionRequestViewFactoryInterface
{
    public function build(WorkflowTransitionRequest $request): array
    {
        return [
            'id' => $request->getId(),
            'resourceKey' => $request->getResourceKey(),
            'resourceId' => $request->getResourceId(),
            'locale' => $request->getLocale(),
            'status' => $request->getStatus()->value,
            'requestedAt' => $request->getRequestedAt()->format(\DATE_ATOM),
            'createdBy' => $this->serializeUser($request->getCreator()),
            'approvalProgress' => $this->buildApprovalProgress($request),
            'reviewers' => \array_map(
                fn (WorkflowTransitionRequestReviewer $reviewer) => $this->serializeReviewer($reviewer),
                $this->sortReviewers($request),
            ),
        ];
    }

    /**
     * @return array{required: int, approved: int, rejected: int}
     */
    private function buildApprovalProgress(WorkflowTransitionRequest $request): array
    {
        $rejected = 0;
        foreach ($request->getReviewers() as $reviewer) {
            if (WorkflowTransitionRequestReviewerStatusEnum::REJECTED === $reviewer->getStatus()) {
                ++$rejected;
            }
        }

        return [
            'required' => $request->getRequiredApprovalCount(),
            'approved' => $request->countApprovals(),
            'rejected' => $rejected,
        ];
    }

    /**
     * The automated checks come first in the order the workflow configured them, then the people in
     * the order they decided, so the list reads the same on every request.
     *
     * @return list<WorkflowTransitionRequestReviewer>
     */
    private function sortReviewers(WorkflowTransitionRequest $request): array
    {
        $validators = [];
        $users = [];
        foreach ($request->getReviewers() as $reviewer) {
            if ($reviewer->isValidator()) {
                $validators[] = $reviewer;
            } else {
                $users[] = $reviewer;
            }
        }

        \usort(
            $users,
            static fn (WorkflowTransitionRequestReviewer $a, WorkflowTransitionRequestReviewer $b) => $a->getDecidedAt() <=> $b->getDecidedAt(),
        );

        return \array_merge($validators, $users);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeReviewer(WorkflowTransitionRequestReviewer $reviewer): array
    {
        return [
            'id' => $reviewer->getId(),
            'type' => $reviewer->isValidator() ? 'validator' : 'user',
            'status' => $reviewer->getStatus()->value,
            'comment' => $reviewer->getComment(),
            'reviewer' => $this->serializeUser($reviewer->getUser()),
            'validatorKey' => $reviewer->getValidatorKey(),
            'decidedAt' => $reviewer->getDecidedAt()?->format(\DATE_ATOM),
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
