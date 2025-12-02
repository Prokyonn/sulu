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

class LegacyRouteTableNotFoundException extends \RuntimeException
{
    public function __construct(string $tableName)
    {
        parent::__construct(\sprintf(
            'Legacy route table "%s" not found. See https://github.com/sulu/sulu/blob/3.0/UPGRADE-3.x.md for the query to create the old routes table.',
            $tableName,
        ));
    }
}
