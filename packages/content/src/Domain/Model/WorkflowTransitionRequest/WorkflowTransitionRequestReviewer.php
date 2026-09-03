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

use Sulu\Component\Persistence\Model\AuditableInterface;
use Sulu\Component\Persistence\Model\AuditableTrait;
use Sulu\Component\Security\Authentication\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * One verdict on a request. A row belongs either to a person or to a validator, and both count the
 * same way: an approved row is an approval, a rejected row is a comment that does not count.
 */
class WorkflowTransitionRequestReviewer implements AuditableInterface
{
    use AuditableTrait;

    private string $id;

    private WorkflowTransitionRequest $workflowTransitionRequest;

    private ?UserInterface $user = null;

    private ?string $validatorKey = null;

    private WorkflowTransitionRequestReviewerStatusEnum $status = WorkflowTransitionRequestReviewerStatusEnum::PENDING;

    private ?string $comment = null;

    private ?\DateTimeImmutable $decidedAt = null;

    private function __construct(WorkflowTransitionRequest $workflowTransitionRequest)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->workflowTransitionRequest = $workflowTransitionRequest;
    }

    public static function forUser(WorkflowTransitionRequest $workflowTransitionRequest, UserInterface $user): self
    {
        $reviewer = new self($workflowTransitionRequest);
        $reviewer->user = $user;

        return $reviewer;
    }

    public static function forValidator(WorkflowTransitionRequest $workflowTransitionRequest, string $validatorKey): self
    {
        $reviewer = new self($workflowTransitionRequest);
        $reviewer->validatorKey = $validatorKey;

        return $reviewer;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    public function getValidatorKey(): ?string
    {
        return $this->validatorKey;
    }

    public function isValidator(): bool
    {
        return null !== $this->validatorKey;
    }

    public function getStatus(): WorkflowTransitionRequestReviewerStatusEnum
    {
        return $this->status;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function approve(?string $comment = null): void
    {
        $this->settle(WorkflowTransitionRequestReviewerStatusEnum::APPROVED, $comment, new \DateTimeImmutable());
    }

    public function reject(string $comment): void
    {
        $this->settle(WorkflowTransitionRequestReviewerStatusEnum::REJECTED, $comment, new \DateTimeImmutable());
    }

    public function resetToPending(): void
    {
        $this->status = WorkflowTransitionRequestReviewerStatusEnum::PENDING;
        $this->comment = null;
        $this->decidedAt = null;
    }

    /**
     * Mirrors a settlement that already happened in the database onto the hydrated row, so an inline
     * run answers the same as a worker run.
     *
     * @internal
     */
    public function settle(WorkflowTransitionRequestReviewerStatusEnum $status, ?string $comment, \DateTimeImmutable $decidedAt): void
    {
        $this->status = $status;
        $this->comment = $comment;
        $this->decidedAt = $decidedAt;
    }
}
