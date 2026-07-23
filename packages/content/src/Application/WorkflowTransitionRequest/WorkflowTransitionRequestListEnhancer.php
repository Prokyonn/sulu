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

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;

/**
 * Helper for content list endpoints (cget) that want to expose a `workflowTransitionRequestStatus` column per row,
 * or restrict the listing to rows that currently have an active workflow transition request. Provides an opt-in
 * pattern that any content type's controller can plug into without adding a SQL join to its list config.
 */
final class WorkflowTransitionRequestListEnhancer implements WorkflowTransitionRequestListEnhancerInterface
{
    public function __construct(
        private readonly WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function enhanceRows(array $rows, string $resourceKey, ?string $locale, string $idField = 'id'): array
    {
        if ([] === $rows || null === $locale || '' === $locale) {
            return $rows;
        }

        $resourceIds = [];
        foreach ($rows as $row) {
            $resourceId = $this->extractResourceId($row, $idField);
            if (null !== $resourceId) {
                $resourceIds[] = $resourceId;
            }
        }

        if ([] === $resourceIds) {
            return $rows;
        }

        $statusByResourceId = [];
        foreach ($this->workflowTransitionRequestRepository->findBy([
            'resourceKey' => $resourceKey,
            'resourceIds' => $resourceIds,
            'locale' => $locale,
            'active' => true,
        ]) as $workflowTransitionRequest) {
            $statusByResourceId[$workflowTransitionRequest->getResourceId()] = $workflowTransitionRequest->getStatus()->value;
        }

        return \array_map(
            function(array $row) use ($idField, $statusByResourceId): array {
                $resourceId = $this->extractResourceId($row, $idField);
                $row['workflowTransitionRequestStatus'] = null !== $resourceId
                    ? ($statusByResourceId[$resourceId] ?? null)
                    : null;

                return $row;
            },
            $rows,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extractResourceId(array $row, string $idField): ?string
    {
        $value = $row[$idField] ?? null;

        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        return null;
    }

    public function findResourceIdsWithActiveRequest(string $resourceKey, ?string $locale): array
    {
        // Scalar query keeps memory flat at list scale: returning hydrated WorkflowTransitionRequest rows would
        // pull every column + reviewers proxy per row just to read `resourceId`.
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT workflowTransitionRequest.resourceId')
            ->from(WorkflowTransitionRequest::class, 'workflowTransitionRequest')
            ->andWhere('workflowTransitionRequest.resourceKey = :resourceKey')
            ->andWhere('workflowTransitionRequest.activeKey IS NOT NULL')
            ->setParameter('resourceKey', $resourceKey);

        if (null !== $locale && '' !== $locale) {
            $queryBuilder->andWhere('workflowTransitionRequest.locale = :locale')
                ->setParameter('locale', $locale);
        }

        /** @var list<array{resourceId: string}> $rows */
        $rows = $queryBuilder->getQuery()->getScalarResult();

        return \array_map(static fn (array $row): string => $row['resourceId'], $rows);
    }
}
