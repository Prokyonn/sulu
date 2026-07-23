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
            $known = \array_keys($this->providers);

            throw new \RuntimeException(\sprintf(
                'No security context provider registered for resource key "%s". %s '
                . 'Each resource type that supports workflow transition requests must register a '
                . 'service implementing %s and tag it with "sulu_content.workflow_transition_request_security_context_provider".',
                $resourceKey,
                [] === $known
                    ? 'No providers are registered at all.'
                    : \sprintf('Registered keys: %s.', \implode(', ', $known)),
                ResourceSecurityContextProviderInterface::class,
            ));
        }

        return $this->providers[$resourceKey]->resolve($resourceId);
    }
}
