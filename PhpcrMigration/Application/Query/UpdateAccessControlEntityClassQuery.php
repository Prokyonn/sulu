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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Query;

use Doctrine\DBAL\Connection;

class UpdateAccessControlEntityClassQuery implements PostMigrationQueryInterface
{
    public function execute(Connection $connection): void
    {
        $connection->executeStatement(
            'UPDATE se_access_controls SET entityClass = :newEntityClass WHERE entityClass = :oldEntityClass',
            [
                'newEntityClass' => 'Sulu\\Page\\Domain\\Model\\Page',
                'oldEntityClass' => 'Sulu\\Component\\Content\\Document\\Behavior\\SecurityBehavior',
            ]
        );
    }
}
