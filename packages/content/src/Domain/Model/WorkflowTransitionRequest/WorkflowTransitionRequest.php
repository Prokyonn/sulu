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

    private WorkflowTransitionRequestStatusEnum $status = WorkflowTransitionRequestStatusEnum::PENDING;

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

    public function getUuid(): string
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

    public function getStatus(): WorkflowTransitionRequestStatusEnum
    {
        return $this->status;
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

    public function getRequiredApprovalCount(): ?int
    {
        return $this->requiredApprovalCount;
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

    public function addApproval(UserInterface $user, ?string $comment = null): void
    {
        if ($this->status->isClosed()) {
            throw new WorkflowTransitionRequestClosedException($this);
        }

        $this->assertNotCreator($user);

        $this->updateOrAddReviewer($user, WorkflowTransitionRequestReviewerStatusEnum::APPROVED, $comment);
    }

    public function addRejection(UserInterface $user, ?string $comment = null): void
    {
        if ($this->status->isClosed()) {
            throw new WorkflowTransitionRequestClosedException($this);
        }

        $this->assertNotCreator($user);

        $this->updateOrAddReviewer($user, WorkflowTransitionRequestReviewerStatusEnum::REJECTED, $comment);
    }

    public function cancel(): void
    {
        $this->transitionTo(WorkflowTransitionRequestStatusEnum::CANCELLED);
    }

    public function cancelByUser(UserInterface $user): void
    {
        if ($this->getCreator() !== $user) {
            throw new WorkflowTransitionRequestCancelNotAllowedException($this);
        }

        $this->cancel();
    }

    public function publish(): void
    {
        $this->transitionTo(WorkflowTransitionRequestStatusEnum::PUBLISHED);
    }

    /**
     * Set the derived status. Called by {@see \Sulu\Content\Application\RequestWorkflow\RequestWorkflowEvaluator}
     * after running validators; not intended for direct caller use.
     *
     * @internal
     */
    public function updateStatus(WorkflowTransitionRequestStatusEnum $status): void
    {
        if ($this->status->isClosed()) {
            return;
        }
        $this->transitionTo($status);
    }

    private function assertNotCreator(UserInterface $user): void
    {
        $creator = $this->getCreator();
        if (null === $creator) {
            throw new \LogicException(\sprintf(
                'WorkflowTransitionRequest "%s" has no creator yet; the self-review check cannot be evaluated.',
                $this->id,
            ));
        }

        if ($creator === $user) {
            throw new SelfReviewNotAllowedException($this);
        }
    }

    private function updateOrAddReviewer(UserInterface $user, WorkflowTransitionRequestReviewerStatusEnum $status, ?string $comment): void
    {
        foreach ($this->reviewers as $reviewer) {
            if ($reviewer->getCreator() === $user) {
                $reviewer->update($status, $comment);

                return;
            }
        }

        $reviewer = new WorkflowTransitionRequestReviewer($user, $status, $comment);
        $reviewer->setWorkflowTransitionRequest($this);
        $this->reviewers->add($reviewer);
    }

    private function transitionTo(WorkflowTransitionRequestStatusEnum $toStatus): void
    {
        if ($this->status === $toStatus) {
            return;
        }

        $this->status = $toStatus;
        $this->syncActiveKey();
    }

    private function syncActiveKey(): void
    {
        $this->activeKey = $this->status->isActive()
            ? \sprintf('%s:%s:%s', $this->resourceKey, $this->resourceId, $this->locale)
            : null;
    }
}
