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

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestNotFoundException;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;

/**
 * @phpstan-type WorkflowTransitionRequestFilters array{
 *     id?: string,
 *     ids?: string[],
 *     resourceKey?: string,
 *     resourceId?: string,
 *     resourceIds?: string[],
 *     locale?: string,
 *     active?: bool,
 *     page?: int,
 *     limit?: int,
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
        } catch (NonUniqueResultException $e) {
            // Defensive: a unique-constraint violation between the active-key column and a concurrent insert
            // should never produce two active rows for the same scope. If it ever does, surface the issue as
            // a 404 rather than a generic 500 so the call site can react sensibly.
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
        } catch (NonUniqueResultException) {
            return null;
        }

        return $workflowTransitionRequest;
    }

    public function findBy(array $filters = [], array $sortBy = []): \Generator
    {
        $queryBuilder = $this->createQueryBuilder($filters, $sortBy);

        /** @var iterable<WorkflowTransitionRequest> $results */
        $results = $queryBuilder->getQuery()->getResult();

        foreach ($results as $result) {
            yield $result;
        }
    }

    public function countBy(array $filters = []): int
    {
        unset($filters['page']); // @phpstan-ignore-line
        unset($filters['limit']); // @phpstan-ignore-line

        $queryBuilder = $this->createQueryBuilder($filters);
        $queryBuilder->select('COUNT(DISTINCT workflowTransitionRequest.id)');

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    public function add(WorkflowTransitionRequest $workflowTransitionRequest): void
    {
        $this->entityManager->persist($workflowTransitionRequest);
    }

    public function remove(WorkflowTransitionRequest $workflowTransitionRequest): void
    {
        $this->entityManager->remove($workflowTransitionRequest);
    }

    /**
     * @param WorkflowTransitionRequestFilters $filters
     * @param array{requestedAt?: 'asc'|'desc'} $sortBy
     */
    private function createQueryBuilder(array $filters, array $sortBy = []): QueryBuilder
    {
        $queryBuilder = $this->entityRepository->createQueryBuilder('workflowTransitionRequest');

        $id = $filters['id'] ?? null;
        if (null !== $id) {
            $queryBuilder->andWhere('workflowTransitionRequest.id = :id')
                ->setParameter('id', $id);
        }

        $ids = $filters['ids'] ?? null;
        if (null !== $ids) {
            $queryBuilder->andWhere('workflowTransitionRequest.id IN (:ids)')
                ->setParameter('ids', $ids);
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

        $resourceIds = $filters['resourceIds'] ?? null;
        if (null !== $resourceIds) {
            $queryBuilder->andWhere('workflowTransitionRequest.resourceId IN (:resourceIds)')
                ->setParameter('resourceIds', $resourceIds);
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

        $page = $filters['page'] ?? null;
        $limit = $filters['limit'] ?? null;
        if (null !== $limit) {
            $queryBuilder->setMaxResults($limit);
            if (null !== $page) {
                $queryBuilder->setFirstResult(($page - 1) * $limit);
            }
        }

        $requestedAtSort = $sortBy['requestedAt'] ?? null;
        if (null !== $requestedAtSort) {
            $queryBuilder->addOrderBy('workflowTransitionRequest.requestedAt', $requestedAtSort);
        }

        return $queryBuilder;
    }
}
