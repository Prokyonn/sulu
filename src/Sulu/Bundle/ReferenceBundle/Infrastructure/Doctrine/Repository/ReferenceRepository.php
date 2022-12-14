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

namespace Sulu\Bundle\ReferenceBundle\Infrastructure\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Sulu\Bundle\ReferenceBundle\Domain\Exception\ReferenceNotFoundException;
use Sulu\Bundle\ReferenceBundle\Domain\Model\ReferenceInterface;
use Sulu\Bundle\ReferenceBundle\Domain\Repository\ReferenceRepositoryInterface;

final class ReferenceRepository implements ReferenceRepositoryInterface
{
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var EntityRepository<ReferenceInterface>
     */
    private $entityRepository;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;

        $this->entityRepository = $this->entityManager->getRepository(ReferenceInterface::class);
    }

    public function create(
        string $sourceResourceKey,
        string $sourceResourceId,
        string $sourceLocale,
        string $sourceWorkflowStage,
        string $sourceSecurityContext,
        string $sourceSecurityObjectType,
        string $sourceSecurityObjectId,
        string $targetResourceKey,
        string $targetResourceId,
        string $targetSecurityContext,
        string $targetSecurityObjectType,
        string $targetSecurityObjectId,
        string $referenceProperty,
        string $referenceGroup,
        string $referenceContext
    ): ReferenceInterface {
        /** @var class-string<ReferenceInterface> $className */
        $className = $this->entityRepository->getClassName();

        /** @var ReferenceInterface $reference */
        $reference = new $className();

        $reference
            ->setSourceResourceKey($sourceResourceKey)
            ->setSourceResourceId($sourceResourceId)
            ->setSourceLocale($sourceLocale)
            ->setSourceWorkflowStage($sourceWorkflowStage)
            ->setSourceSecurityContext($sourceSecurityContext)
            ->setSourceSecurityObjectType($sourceSecurityObjectType)
            ->setSourceSecurityObjectId($sourceSecurityObjectId)
            ->setTargetResourceKey($targetResourceKey)
            ->setTargetResourceId($targetResourceId)
            ->setTargetSecurityContext($targetSecurityContext)
            ->setTargetSecurityObjectType($targetSecurityObjectType)
            ->setTargetSecurityObjectId($targetSecurityObjectId)
            ->setReferenceProperty($referenceProperty)
            ->setReferenceGroup($referenceGroup)
            ->setReferenceContext($referenceContext);

        return $reference;
    }

    public function add(ReferenceInterface $reference): void
    {
        $this->entityManager->persist($reference);
    }

    public function remove(ReferenceInterface $reference): void
    {
        $this->entityManager->remove($reference);
    }

    public function getOneBy(array $criteria): ReferenceInterface
    {
        $reference = $this->findOneBy($criteria);

        if (null === $reference) {
            throw new ReferenceNotFoundException($criteria);
        }

        return $reference;
    }

    public function findOneBy(array $criteria): ?ReferenceInterface
    {
        return $this->entityRepository->findOneBy($criteria);
    }
}
