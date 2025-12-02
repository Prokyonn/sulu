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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Parser;

use PHPCR\NodeInterface;
use PHPCR\PropertyInterface;

/**
 * Parses PHPCR webspace nodes to extract snippet area assignments.
 *
 * Unlike other parsers, snippet areas are stored as properties on webspace nodes
 * with the pattern: settings:snippets-{areaKey} → snippet node reference
 */
class SnippetAreaNodeParser implements NodeParserInterface
{
    public function supports(NodeInterface $node, string $documentType): bool
    {
        if ('snippet_area' !== $documentType) {
            return false;
        }

        // Check if this is a webspace node by checking for snippet area properties
        try {
            $properties = $node->getProperties('settings:snippets-*');
            $propertiesArray = \iterator_to_array($properties);

            return [] !== $propertiesArray;
            // @phpstan-ignore-next-line fail-loud: intentional catch-all for missing properties
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Returns a list of snippet area documents (one per area found on the webspace node).
     *
     * @return array<string, mixed>
     */
    public function parse(NodeInterface $node, string $documentType): array
    {
        if (!$this->supports($node, $documentType)) {
            return [];
        }

        // Extract webspace key from node path (/cmf/{webspaceKey})
        $path = $node->getPath();
        $pathParts = \explode('/', $path);
        $webspaceKey = $pathParts[2] ?? '';

        if ('' === $webspaceKey || '0' === $webspaceKey) {
            return [];
        }

        $snippetAreas = [];

        // Get all snippet area properties (settings:snippets-*)
        $properties = $node->getProperties('settings:snippets-*');

        /** @var PropertyInterface $property */
        foreach ($properties as $property) {
            // Extract area key from property name (settings:snippets-{areaKey})
            $propertyName = $property->getName();
            $areaKey = \substr($propertyName, 18); // Remove 'settings:snippets-' prefix

            // Get snippet UUID from node reference
            $snippetUuid = null;
            try {
                $value = $property->getValue();
                if ($value instanceof NodeInterface) {
                    $snippetUuid = $value->getIdentifier();
                }
                // @phpstan-ignore-next-line fail-loud: intentional catch-all for broken references
            } catch (\Throwable) {
                $snippetUuid = null;
            }

            $snippetAreas[] = [
                'webspaceKey' => $webspaceKey,
                'areaKey' => $areaKey,
                'snippetUuid' => $snippetUuid,
            ];
        }

        /** @var array<string, mixed> $result */
        $result = $snippetAreas;

        return $result;
    }
}
