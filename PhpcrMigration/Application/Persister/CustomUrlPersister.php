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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister;

use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;

/**
 * Persists CustomUrl data to Sulu 3.0 database structure.
 *
 * Handles migration of:
 * - CustomUrl main entity (cu_custom_url table)
 * - CustomUrlRoute entities (cu_custom_url_route table)
 */
class CustomUrlPersister implements PersisterInterface
{
    public function __construct(
        private readonly EntityRepositoryInterface $entityRepository,
    ) {
    }

    public function supports(array $document): bool
    {
        return isset($document['baseDomain']) && isset($document['domainParts']);
    }

    public static function getType(): string
    {
        return 'custom_url';
    }

    /**
     * @param array{
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
     * } $document
     */
    public function persist(array $document, bool $isLive): void
    {
        // Insert or update CustomUrl entity
        $customUrlData = [
            'uuid' => $document['uuid'],
            'title' => $document['title'],
            'published' => $document['published'],
            'baseDomain' => $document['baseDomain'],
            'webspace' => $document['webspace'],
            'domainParts' => $document['domainParts'],
            'targetDocument' => $document['targetDocument'],
            'targetLocale' => $document['targetLocale'],
            'canonical' => $document['canonical'],
            'redirect' => $document['redirect'],
            'noFollow' => $document['noFollow'],
            'noIndex' => $document['noIndex'],
        ];

        $this->entityRepository->insertOrUpdate(
            $customUrlData,
            'cu_custom_url',
            [
                'uuid' => 'string',
                'title' => 'string',
                'published' => 'boolean',
                'baseDomain' => 'string',
                'webspace' => 'string',
                'domainParts' => 'json',
                'targetDocument' => 'string',
                'targetLocale' => 'string',
                'canonical' => 'boolean',
                'redirect' => 'boolean',
                'noFollow' => 'boolean',
                'noIndex' => 'boolean',
            ],
        );

        // Remove existing routes for this custom URL
        $this->entityRepository->removeBy(
            'cu_custom_url_route',
            ['customUrl' => $document['uuid']],
        );

        // Insert routes
        foreach ($document['routes'] as $route) {
            $routeData = [
                'uuid' => $route['uuid'],
                'customUrl' => $document['uuid'],
                'path' => $route['path'],
                'history' => $route['history'],
                'target_route_uuid' => null, // Will be handled by Sulu 3.0 routing logic
            ];

            $this->entityRepository->insertOrUpdate(
                $routeData,
                'cu_custom_url_route',
                [
                    'uuid' => 'string',
                    'customUrl' => 'string',
                    'path' => 'string',
                    'history' => 'boolean',
                    'target_route_uuid' => 'string',
                ],
            );
        }
    }
}
