<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception;

class EntityNotFoundException extends \RuntimeException
{
    /**
     * @param array<string, string> $filters The filters used to search for the entity
     */
    public function __construct(string $entityType, array $filters = [])
    {
        $filtersString = \implode(', ', \array_map(static fn ($key, $value) => \sprintf('%s: %s', $key, $value), \array_keys($filters), $filters));

        parent::__construct(\sprintf(
            'Entity class "%s" could not be found with filters [%s].',
            $entityType,
            $filtersString,
        ));
    }
}
