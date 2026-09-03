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

namespace Sulu\Content\Domain\Model\WorkflowTransitionRequest;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Sulu\Component\Persistence\Model\AuditableInterface;
use Sulu\Component\Persistence\Model\AuditableTrait;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Content\Domain\Exception\SelfReviewNotAllowedException;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestCancelNotAllowedException;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestClosedException;
use Symfony\Component\Uid\Uuid;

class WorkflowTransitionRequest implements AuditableInterface
{
    use AuditableTrait;

    public const DEFAULT_WORKFLOW_NAME = 'default';

    private string $id;

    private WorkflowTransitionRequestLifecycleEnum $lifecycle = WorkflowTransitionRequestLifecycleEnum::OPEN;

    private ?string $activeKey = null;

    private \DateTimeImmutable $requestedAt;

    private string $workflowName;

    /**
     * Snapshot of the number of approvals required at the time the request was created. Kept stable
     * even if the workflow config changes later, so an in-flight request keeps its original gate.
     */
    private ?int $requiredApprovalCount = null;

    /**
     * @var Collection<int, WorkflowTransitionRequestReviewer>
     */
    private Collection $reviewers;

    public function __construct(
        private readonly string $resourceKey,
        private readonly string $resourceId,
        private readonly string $locale,
        string $workflowName = self::DEFAULT_WORKFLOW_NAME,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->requestedAt = new \DateTimeImmutable();
        $this->reviewers = new ArrayCollection();
        $this->workflowName = $workflowName;
        $this->syncActiveKey();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getResourceKey(): string
    {
        return $this->resourceKey;
    }

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function isOpen(): bool
    {
        return $this->lifecycle->isOpen();
    }

    /**
     * Derived on read and never stored, so two handlers settling concurrently cannot overwrite
     * each other's verdict.
     */
    public function getStatus(): WorkflowTransitionRequestStatusEnum
    {
        if (WorkflowTransitionRequestLifecycleEnum::CANCELLED === $this->lifecycle) {
            return WorkflowTransitionRequestStatusEnum::CANCELLED;
        }

        if (WorkflowTransitionRequestLifecycleEnum::PUBLISHED === $this->lifecycle) {
            return WorkflowTransitionRequestStatusEnum::PUBLISHED;
        }

        return $this->countApprovals() >= $this->getRequiredApprovalCount()
            ? WorkflowTransitionRequestStatusEnum::APPROVED
            : WorkflowTransitionRequestStatusEnum::PENDING;
    }

    /**
     * A passed validator counts like a person's approval. A rejection is a comment on what is missing
     * and simply does not count, it never blocks the request.
     */
    public function countApprovals(): int
    {
        $approvals = 0;
        foreach ($this->reviewers as $reviewer) {
            if (WorkflowTransitionRequestReviewerStatusEnum::APPROVED === $reviewer->getStatus()) {
                ++$approvals;
            }
        }

        return $approvals;
    }

    public function getActiveKey(): ?string
    {
        return $this->activeKey;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getWorkflowName(): string
    {
        return $this->workflowName;
    }

    public function getRequiredApprovalCount(): int
    {
        return $this->requiredApprovalCount ?? 1;
    }

    public function setRequiredApprovalCount(int $requiredApprovalCount): void
    {
        $this->requiredApprovalCount = $requiredApprovalCount;
    }

    /**
     * @return list<WorkflowTransitionRequestReviewer>
     */
    public function getReviewers(): array
    {
        return \array_values($this->reviewers->toArray());
    }

    public function addValidator(string $validatorKey): void
    {
        $this->reviewers->add(WorkflowTransitionRequestReviewer::forValidator($this, $validatorKey));
    }

    public function getValidatorReviewer(string $validatorKey): ?WorkflowTransitionRequestReviewer
    {
        foreach ($this->reviewers as $reviewer) {
            if ($validatorKey === $reviewer->getValidatorKey()) {
                return $reviewer;
            }
        }

        return null;
    }

    public function addApproval(UserInterface $user, ?string $comment = null): void
    {
        $this->resolveUserReviewer($user)->approve($comment);
    }

    public function addRejection(UserInterface $user, string $comment): void
    {
        $this->resolveUserReviewer($user)->reject($comment);
    }

    public function cancel(): void
    {
        $this->transitionTo(WorkflowTransitionRequestLifecycleEnum::CANCELLED);
    }

    /**
     * A deleted creator leaves the row without an owner, so the content would stay locked forever if
     * only the creator could cancel. Anyone who reaches the action may then free it.
     */
    public function cancelByUser(UserInterface $user): void
    {
        $creator = $this->getCreator();
        if (null !== $creator && $creator !== $user) {
            throw new WorkflowTransitionRequestCancelNotAllowedException($this);
        }

        $this->cancel();
    }

    public function publish(): void
    {
        $this->transitionTo(WorkflowTransitionRequestLifecycleEnum::PUBLISHED);
    }

    private function resolveUserReviewer(UserInterface $user): WorkflowTransitionRequestReviewer
    {
        if (!$this->lifecycle->isOpen()) {
            throw new WorkflowTransitionRequestClosedException($this);
        }

        if ($this->getCreator() === $user) {
            throw new SelfReviewNotAllowedException($this);
        }

        foreach ($this->reviewers as $reviewer) {
            if ($reviewer->getUser() === $user) {
                return $reviewer;
            }
        }

        $reviewer = WorkflowTransitionRequestReviewer::forUser($this, $user);
        $this->reviewers->add($reviewer);

        return $reviewer;
    }

    private function transitionTo(WorkflowTransitionRequestLifecycleEnum $toLifecycle): void
    {
        if (!$this->lifecycle->isOpen()) {
            return;
        }

        $this->lifecycle = $toLifecycle;
        $this->syncActiveKey();
    }

    private function syncActiveKey(): void
    {
        $this->activeKey = $this->lifecycle->isOpen()
            ? \sprintf('%s:%s:%s', $this->resourceKey, $this->resourceId, $this->locale)
            : null;
    }
}
