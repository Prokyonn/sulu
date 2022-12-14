<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\ReferenceBundle\Domain\Repository;

use Sulu\Bundle\ReferenceBundle\Domain\Model\ReferenceInterface;

interface ReferenceRepositoryInterface
{
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
    ): ReferenceInterface;

    public function add(ReferenceInterface $reference): void;

    public function remove(ReferenceInterface $reference): void;

    public function getOneBy(array $criteria): ReferenceInterface;

    public function findOneBy(array $criteria): ?ReferenceInterface;
}
