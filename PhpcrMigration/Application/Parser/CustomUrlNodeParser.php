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

/**
 * Parses PHPCR CustomUrl nodes to array structure for Sulu 3.0 migration.
 */
class CustomUrlNodeParser implements NodeParserInterface
{
    public function __construct(
        private readonly PropertyNodeParser $propertyNodeParser,
    ) {
    }

    public function supports(NodeInterface $node, string $documentType): bool
    {
        if ('custom_url' !== $documentType) {
            return false;
        }

        foreach ($node->getMixinNodeTypes() as $mixinNodeType) {
            if ('sulu:custom_url' === $mixinNodeType->getName()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{}|array{
     *     uuid: string,
     *     title: string,
     *     published: bool,
     *     baseDomain: string,
     *     webspace: string,
     *     domainParts: array<string>,
     *     targetDocument: string|null,
     *     targetLocale: string,
     *     canonical: bool,
     *     redirect: bool,
     *     noFollow: bool,
     *     noIndex: bool,
     *     routes: array<int, array{uuid: string, path: string, history: bool}>,
     *     created: \DateTimeInterface,
     *     changed: \DateTimeInterface,
     *     creator: int|null,
     *     changer: int,
     * }
     */
    public function parse(NodeInterface $node, string $documentType): array
    {
        if (!$this->supports($node, $documentType)) {
            return [];
        }

        // Parse base properties using PropertyNodeParser
        $baseData = $this->propertyNodeParser->parse($node, $documentType);

        if ([] === $baseData) {
            return [];
        }

        // Extract webspace from path (/cmf/<webspace>/custom-urls/...)
        $path = $node->getPath();
        $pathParts = \explode('/', $path);
        $webspace = $pathParts[2] ?? '';

        // Parse routes from child nodes
        $routes = [];
        if ($node->hasNode('routes')) {
            $routesNode = $node->getNode('routes');
            foreach ($routesNode->getNodes() as $routeNode) {
                $uuidValue = $routeNode->getProperty('jcr:uuid')->getString();
                $pathValue = $routeNode->getProperty('sulu:path')->getString();
                $historyValue = $routeNode->hasProperty('sulu:history')
                    ? $routeNode->getProperty('sulu:history')->getBoolean()
                    : false;

                $routes[] = [
                    'uuid' => \is_array($uuidValue) ? $uuidValue[0] : $uuidValue,
                    'path' => \is_array($pathValue) ? $pathValue[0] : $pathValue,
                    'history' => \is_array($historyValue) ? (bool) $historyValue[0] : $historyValue,
                ];
            }
        }

        // Extract values with type assertions
        /** @var array{uuid?: string} $jcrData */
        $jcrData = $baseData['jcr'];
        /** @var array{title?: string, published?: bool, baseDomain?: string, domainParts?: array<string>, targetDocument?: string, targetLocale?: string, canonical?: bool, redirect?: bool, noFollow?: bool, noIndex?: bool} $nullLocalization */
        $nullLocalization = $baseData['localizations']['null'] ?? [];
        /** @var array{created?: \DateTimeInterface, changed?: \DateTimeInterface, creator?: int, changer?: int} $suluData */
        $suluData = $baseData['sulu'];

        return [
            'uuid' => $jcrData['uuid'] ?? '',
            'title' => $nullLocalization['title'] ?? '',
            'published' => $nullLocalization['published'] ?? false,
            'baseDomain' => $nullLocalization['baseDomain'] ?? '',
            'webspace' => $webspace,
            'domainParts' => $nullLocalization['domainParts'] ?? [],
            'targetDocument' => $nullLocalization['targetDocument'] ?? null,
            'targetLocale' => $nullLocalization['targetLocale'] ?? 'en',
            'canonical' => $nullLocalization['canonical'] ?? false,
            'redirect' => $nullLocalization['redirect'] ?? false,
            'noFollow' => $nullLocalization['noFollow'] ?? false,
            'noIndex' => $nullLocalization['noIndex'] ?? false,
            'routes' => $routes,
            'created' => $suluData['created'] ?? new \DateTime(),
            'changed' => $suluData['changed'] ?? new \DateTime(),
            'creator' => $suluData['creator'] ?? null,
            'changer' => $suluData['changer'] ?? 0,
        ];
    }
}
