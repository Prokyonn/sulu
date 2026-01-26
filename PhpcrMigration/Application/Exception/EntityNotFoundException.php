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
    public function __construct(string $uuid)
    {
        parent::__construct(\sprintf('Entity (Page/Article) with the uuid "%s" could not be found.', $uuid));
    }
}
