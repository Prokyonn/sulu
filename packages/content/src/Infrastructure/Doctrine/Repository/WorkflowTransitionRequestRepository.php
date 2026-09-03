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

namespace Sulu\Content\Infrastructure\Doctrine\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestNotFoundException;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewer;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewerStatusEnum;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;

/**
 * @phpstan-type WorkflowTransitionRequestFilters array{
 *     id?: string,
 *     resourceKey?: string,
 *     resourceId?: string,
 *     locale?: string,
 *     active?: bool,
 * }
 */
final class WorkflowTransitionRequestRepository implements WorkflowTransitionRequestRepositoryInterface
{
    /**
     * @var EntityRepository<WorkflowTransitionRequest>
     */
    private readonly EntityRepository $entityRepository;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        $this->entityRepository = $entityManager->getRepository(WorkflowTransitionRequest::class);
    }

    public function getOneBy(array $filters): WorkflowTransitionRequest
    {
        try {
            /** @var WorkflowTransitionRequest $workflowTransitionRequest */
            $workflowTransitionRequest = $this->createQueryBuilder($filters)->getQuery()->getSingleResult();
        } catch (NoResultException $e) {
            throw new WorkflowTransitionRequestNotFoundException($filters, 0, $e);
        }

        return $workflowTransitionRequest;
    }

    public function findOneBy(array $filters): ?WorkflowTransitionRequest
    {
        try {
            /** @var WorkflowTransitionRequest $workflowTransitionRequest */
            $workflowTransitionRequest = $this->createQueryBuilder($filters)->getQuery()->getSingleResult();
        } catch (NoResultException) {
            return null;
        }

        return $workflowTransitionRequest;
    }

    public function countBy(array $filters = []): int
    {
        $queryBuilder = $this->createQueryBuilder($filters);
        $queryBuilder->select('COUNT(DISTINCT workflowTransitionRequest.id)');

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    public function add(WorkflowTransitionRequest $workflowTransitionRequest): void
    {
        $this->entityManager->persist($workflowTransitionRequest);
    }

    public function settleValidatorReviewer(
        WorkflowTransitionRequestReviewer $reviewer,
        WorkflowTransitionRequestReviewerStatusEnum $status,
        ?string $comment,
    ): void {
        $decidedAt = new \DateTimeImmutable();
        $tableName = $this->entityManager->getClassMetadata(WorkflowTransitionRequestReviewer::class)->getTableName();

        $claimed = (int) $this->entityManager->getConnection()->executeStatement(
            \sprintf(
                'UPDATE %s SET status = :status, comment = :comment, decided_at = :decidedAt
                    WHERE id = :id AND status = :pending',
                $tableName,
            ),
            [
                'status' => $status->value,
                'comment' => $comment,
                'decidedAt' => $decidedAt,
                'id' => $reviewer->getId(),
                'pending' => WorkflowTransitionRequestReviewerStatusEnum::PENDING->value,
            ],
            ['decidedAt' => Types::DATETIME_IMMUTABLE],
        );

        if (1 !== $claimed) {
            return;
        }

        // The statement bypasses the unit of work, so the hydrated row would otherwise stay `pending`
        // for the rest of an inline run and the response would contradict the database.
        $reviewer->settle($status, $comment, $decidedAt);
    }

    /**
     * @param WorkflowTransitionRequestFilters $filters
     */
    private function createQueryBuilder(array $filters): QueryBuilder
    {
        $queryBuilder = $this->entityRepository->createQueryBuilder('workflowTransitionRequest');

        $id = $filters['id'] ?? null;
        if (null !== $id) {
            $queryBuilder->andWhere('workflowTransitionRequest.id = :id')
                ->setParameter('id', $id);
        }

        $resourceKey = $filters['resourceKey'] ?? null;
        if (null !== $resourceKey) {
            $queryBuilder->andWhere('workflowTransitionRequest.resourceKey = :resourceKey')
                ->setParameter('resourceKey', $resourceKey);
        }

        $resourceId = $filters['resourceId'] ?? null;
        if (null !== $resourceId) {
            $queryBuilder->andWhere('workflowTransitionRequest.resourceId = :resourceId')
                ->setParameter('resourceId', $resourceId);
        }

        $locale = $filters['locale'] ?? null;
        if (null !== $locale) {
            $queryBuilder->andWhere('workflowTransitionRequest.locale = :locale')
                ->setParameter('locale', $locale);
        }

        $active = $filters['active'] ?? null;
        if (null !== $active) {
            if ($active) {
                $queryBuilder->andWhere('workflowTransitionRequest.activeKey IS NOT NULL');
            } else {
                $queryBuilder->andWhere('workflowTransitionRequest.activeKey IS NULL');
            }
        }

        return $queryBuilder;
    }
}
