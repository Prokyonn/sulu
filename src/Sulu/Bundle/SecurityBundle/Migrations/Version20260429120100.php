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

namespace Sulu\Bundle\SecurityBundle\Migrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename legacy permission contexts in `se_permissions`:
 * - `sulu.modules.articles` → `sulu.article.articles`
 * - `sulu.global.snippets`  → `sulu.snippet.snippets`
 *
 * Idempotent: if a role already has a row with the new context, the legacy row is removed
 * instead of updated to avoid violating the (context, idRoles) unique constraint.
 */
final class Version20260429120100 extends AbstractMigration
{
    /**
     * @var array<string, string>
     */
    private const RENAMES = [
        'sulu.modules.articles' => 'sulu.article.articles',
        'sulu.global.snippets' => 'sulu.snippet.snippets',
    ];

    public function getDescription(): string
    {
        return 'Rename legacy permission contexts (sulu.modules.articles → sulu.article.articles, sulu.global.snippets → sulu.snippet.snippets)';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('se_permissions')) {
            return;
        }

        foreach (self::RENAMES as $old => $new) {
            $this->renameContext($old, $new);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('se_permissions')) {
            return;
        }

        foreach (self::RENAMES as $old => $new) {
            $this->renameContext($new, $old);
        }
    }

    private function renameContext(string $oldContext, string $newContext): void
    {
        $conflictingRoleIds = $this->connection->createQueryBuilder()
            ->select('idRoles')
            ->from('se_permissions')
            ->where('context = :newContext')
            ->setParameter('newContext', $newContext)
            ->executeQuery()
            ->fetchFirstColumn();

        $update = $this->connection->createQueryBuilder()
            ->update('se_permissions')
            ->set('context', ':newContext')
            ->where('context = :oldContext')
            ->setParameter('newContext', $newContext)
            ->setParameter('oldContext', $oldContext);

        if ([] !== $conflictingRoleIds) {
            $update
                ->andWhere('idRoles NOT IN (:conflictingRoleIds)')
                ->setParameter('conflictingRoleIds', $conflictingRoleIds, ArrayParameterType::INTEGER);
        }

        $update->executeStatement();

        // Any old-context rows still present are duplicates of an already-existing new-context row for the same role.
        $this->connection->createQueryBuilder()
            ->delete('se_permissions')
            ->where('context = :oldContext')
            ->setParameter('oldContext', $oldContext)
            ->executeStatement();
    }
}
