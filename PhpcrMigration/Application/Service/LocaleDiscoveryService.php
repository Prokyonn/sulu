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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Service;

use PHPCR\NodeInterface;

class LocaleDiscoveryService
{
    /**
     * @var array<string, string[]>
     */
    private array $localeCache = [];

    /**
     * @return string[]
     */
    public function discoverLocales(NodeInterface $node): array
    {
        $nodeIdentifier = $node->getIdentifier();
        if (isset($this->localeCache[$nodeIdentifier])) {
            return $this->localeCache[$nodeIdentifier];
        }

        $locales = [];

        $prefix = 'i18n';
        foreach ($node->getProperties() as $property) {
            \preg_match(
                \sprintf('/^%s:([a-zA-Z_]*?)-.*/', $prefix),
                $property->getName(),
                $matches
            );

            if ([] !== $matches) {
                $locales[$matches[1]] = $matches[1];
            }
        }

        $this->localeCache[$nodeIdentifier] = $locales;

        return $locales;
    }

    public function clearCache(): void
    {
        $this->localeCache = [];
    }
}
