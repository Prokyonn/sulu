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

namespace Sulu\Content\Tests\Application\ExampleTestBundle\Security;

use Sulu\Content\Application\Security\ResourceSecurityContextProviderInterface;
use Sulu\Content\Tests\Application\ExampleTestBundle\Admin\ExampleAdmin;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;

final class ExampleSecurityContextProvider implements ResourceSecurityContextProviderInterface
{
    public function getResourceKey(): string
    {
        return Example::RESOURCE_KEY;
    }

    public function resolve(string $resourceId): string
    {
        return ExampleAdmin::SECURITY_CONTEXT;
    }
}
