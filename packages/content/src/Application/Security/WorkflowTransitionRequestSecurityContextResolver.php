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

final class WorkflowTransitionRequestSecurityContextResolver implements WorkflowTransitionRequestSecurityContextResolverInterface
{
    /**
     * @var array<string, ResourceSecurityContextProviderInterface>
     */
    private array $providers = [];

    /**
     * @param iterable<ResourceSecurityContextProviderInterface> $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->getResourceKey()] = $provider;
        }
    }

    public function resolve(string $resourceKey, string $resourceId): string
    {
        if (!isset($this->providers[$resourceKey])) {
            throw new \RuntimeException(\sprintf(
                'No security context provider registered for resource key "%s", known keys: %s.',
                $resourceKey,
                \implode(', ', \array_keys($this->providers)),
            ));
        }

        return $this->providers[$resourceKey]->resolve($resourceId);
    }
}
