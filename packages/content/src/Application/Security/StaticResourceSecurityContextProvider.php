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

namespace Sulu\Content\Application\Security;

final class StaticResourceSecurityContextProvider implements ResourceSecurityContextProviderInterface
{
    public function __construct(
        private readonly string $resourceKey,
        private readonly string $securityContext,
    ) {
    }

    public function getResourceKey(): string
    {
        return $this->resourceKey;
    }

    public function resolve(string $resourceId): string
    {
        return $this->securityContext;
    }
}
