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
 * Persists snippet area assignments to Sulu 3.0 database structure.
 *
 * Handles migration of:
 * - Snippet area assignments (sn_snippet_area table)
 * - Webspace + area key + snippet reference mapping
 */
class SnippetAreaPersister implements PersisterInterface
{
    public function __construct(
        private readonly EntityRepositoryInterface $entityRepository,
    ) {
    }

    public function supports(array $document): bool
    {
        return isset($document['webspaceKey']) && isset($document['areaKey']);
    }

    public static function getType(): string
    {
        return 'snippet_area';
    }

    /**
     * @param array{
     *     webspaceKey: string,
     *     areaKey: string,
     *     snippetUuid: string|null,
     * } $document
     */
    public function persist(array $document, bool $isLive): void
    {
        // Generate UUID for snippet area (not present in PHPCR)
        // Use xxHash 32-bit with microsecond timestamp (same approach as block IDs)
        $uuid = \hash('xxh32', \uniqid('', true));

        $snippetAreaData = [
            'uuid' => $uuid,
            'webspace_key' => $document['webspaceKey'],
            'area_key' => $document['areaKey'],
            'idSnippet' => $document['snippetUuid'],
        ];

        // Check if snippet area already exists for this webspace + area combination
        $existing = $this->entityRepository->findOneBy(
            'sn_snippet_area',
            [
                'webspace_key' => $document['webspaceKey'],
                'area_key' => $document['areaKey'],
            ],
        );

        if ($existing) {
            // Use existing UUID and update
            $snippetAreaData['uuid'] = $existing['uuid'];
        }

        // Insert or update snippet area
        $this->entityRepository->insertOrUpdate(
            $snippetAreaData,
            'sn_snippet_area',
            [
                'uuid' => 'string',
                'webspace_key' => 'string',
                'area_key' => 'string',
                'idSnippet' => 'string',
            ],
            ['uuid' => $snippetAreaData['uuid']],
        );
    }
}
