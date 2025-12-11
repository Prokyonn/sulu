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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Query;

use Doctrine\DBAL\Connection;

/**
 * Migrates permission contexts from Sulu 2.6 to Sulu 3.0 structure.
 *
 * Updates old permission contexts to new naming:
 * - sulu.modules.articles* → sulu.article.articles*
 * - sulu.global.snippets → sulu.snippet.snippets + sulu.snippet.snippet_areas
 */
class MigratePermissionContextsQuery implements PostMigrationQueryInterface
{
    private const ARCHIVE_PERMISSION = 16;

    /**
     * @var array<string, string>
     */
    private const ARTICLE_CONTEXT_MAPPING = [
        'sulu.modules.articles' => 'sulu.article.articles',
        'sulu.modules.articles_blog' => 'sulu.article.articles_blog',
        'sulu.modules.articles_hubspot' => 'sulu.article.articles_hubspot',
        'sulu.modules.articles_rblog' => 'sulu.article.articles_rblog',
    ];

    private const SNIPPET_OLD_CONTEXT = 'sulu.global.snippets';
    private const SNIPPET_NEW_CONTEXT = 'sulu.snippet.snippets';
    private const SNIPPET_AREA_CONTEXT = 'sulu.snippet.snippet_areas';

    public function execute(Connection $connection): void
    {
        $this->migrateArticlePermissions($connection);
        $this->migrateSnippetPermissions($connection);
    }

    private function migrateArticlePermissions(Connection $connection): void
    {
        // Update all article context names from old to new format
        foreach (self::ARTICLE_CONTEXT_MAPPING as $oldContext => $newContext) {
            $connection->executeStatement(
                'UPDATE se_permissions SET context = :newContext WHERE context = :oldContext',
                [
                    'newContext' => $newContext,
                    'oldContext' => $oldContext,
                ]
            );
        }
    }

    private function migrateSnippetPermissions(Connection $connection): void
    {
        // Find all roles with permissions to old snippet context (before updating)
        $existingPermissions = $connection->fetchAllAssociative(
            'SELECT permissions, idRoles FROM se_permissions WHERE context = :oldContext',
            ['oldContext' => self::SNIPPET_OLD_CONTEXT]
        );

        // Update old snippet context to new snippets context
        $connection->executeStatement(
            'UPDATE se_permissions SET context = :newContext WHERE context = :oldContext',
            [
                'newContext' => self::SNIPPET_NEW_CONTEXT,
                'oldContext' => self::SNIPPET_OLD_CONTEXT,
            ]
        );

        // Create snippet_areas entries for each role that had snippet permissions
        foreach ($existingPermissions as $permission) {
            $exists = $connection->fetchOne(
                'SELECT COUNT(*) FROM se_permissions WHERE context = :areaContext AND idRoles = :idRoles',
                [
                    'areaContext' => self::SNIPPET_AREA_CONTEXT,
                    'idRoles' => $permission['idRoles'],
                ]
            );

            if (!$exists) {
                $connection->insert('se_permissions', [
                    'context' => self::SNIPPET_AREA_CONTEXT,
                    'permissions' => self::ARCHIVE_PERMISSION,
                    'idRoles' => $permission['idRoles'],
                ]);
            }
        }
    }
}
