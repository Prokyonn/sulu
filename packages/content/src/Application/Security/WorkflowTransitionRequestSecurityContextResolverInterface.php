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

interface WorkflowTransitionRequestSecurityContextResolverInterface
{
    /**
     * Resolves the Sulu admin security context string for the given resource.
     *
     * @throws \RuntimeException if no provider is registered for the resource key
     */
    public function resolve(string $resourceKey, string $resourceId): string;
}
