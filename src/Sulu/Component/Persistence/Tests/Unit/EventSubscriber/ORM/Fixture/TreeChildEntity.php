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

namespace Sulu\Component\Persistence\Tests\Unit\EventSubscriber\ORM\Fixture;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class TreeChildEntity extends TreeMappedSuperclass
{
}
