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

namespace Sulu\Snippet\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migrate snippet area permissions from the global `sulu.snippet.snippet_areas` context to
 * per-webspace `sulu.webspaces.{webspaceKey}.snippet-areas` contexts. Webspace keys are derived
 * from existing `sulu.webspaces.*` entries already present in `se_permissions`.
 *
 * Idempotent: per (role, webspace) pair the new row is only inserted when missing, and the
 * legacy rows are removed at the end. Re-runs find no legacy rows and become a no-op.
 */
final class Version20260429120300 extends AbstractMigration
{
    private const OLD_CONTEXT = 'sulu.snippet.snippet_areas';

    private const WEBSPACE_PREFIX = 'sulu.webspaces.';

    private const SNIPPET_AREAS_SUFFIX = '.snippet-areas';

    public function getDescription(): string
    {
        return 'Migrate snippet area permissions from sulu.snippet.snippet_areas to per-webspace sulu.webspaces.{webspace}.snippet-areas';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('se_permissions')) {
            return;
        }

        $oldRows = $this->connection->createQueryBuilder()
            ->select('idRoles', 'permissions')
            ->from('se_permissions')
            ->where('context = :oldContext')
            ->setParameter('oldContext', self::OLD_CONTEXT)
            ->executeQuery()
            ->fetchAllAssociative();

        if ([] === $oldRows) {
            return;
        }

        $webspaceKeys = $this->discoverWebspaceKeys();

        if ([] !== $webspaceKeys) {
            $existing = $this->loadExistingTargetKeys();

            foreach ($oldRows as $row) {
                $roleId = (int) $row['idRoles'];
                $permissions = $row['permissions'];

                foreach ($webspaceKeys as $webspaceKey) {
                    $newContext = self::WEBSPACE_PREFIX . $webspaceKey . self::SNIPPET_AREAS_SUFFIX;
                    $key = $roleId . '|' . $newContext;

                    if (isset($existing[$key])) {
                        continue;
                    }

                    $this->connection->createQueryBuilder()
                        ->insert('se_permissions')
                        ->values([
                            'context' => ':context',
                            'permissions' => ':permissions',
                            'idRoles' => ':roleId',
                        ])
                        ->setParameter('context', $newContext)
                        ->setParameter('permissions', $permissions)
                        ->setParameter('roleId', $roleId)
                        ->executeStatement();

                    $existing[$key] = true;
                }
            }
        }

        $this->connection->createQueryBuilder()
            ->delete('se_permissions')
            ->where('context = :oldContext')
            ->setParameter('oldContext', self::OLD_CONTEXT)
            ->executeStatement();
    }

    public function down(Schema $schema): void
    {
        // Reverse migration not implemented: a single global permission cannot be reliably
        // reconstructed from per-webspace rows that may have diverged after the upgrade.
    }

    /**
     * @return list<string>
     */
    private function discoverWebspaceKeys(): array
    {
        $contexts = $this->connection->createQueryBuilder()
            ->select('DISTINCT context')
            ->from('se_permissions')
            ->where('context LIKE :pattern')
            ->setParameter('pattern', self::WEBSPACE_PREFIX . '%')
            ->executeQuery()
            ->fetchFirstColumn();

        $keys = [];
        foreach ($contexts as $context) {
            $context = (string) $context;
            if (!\str_starts_with($context, self::WEBSPACE_PREFIX)) {
                continue;
            }
            $remainder = \substr($context, \strlen(self::WEBSPACE_PREFIX));
            $key = \explode('.', $remainder, 2)[0];
            if ('' !== $key) {
                $keys[$key] = true;
            }
        }

        return \array_keys($keys);
    }

    /**
     * @return array<string, true>
     */
    private function loadExistingTargetKeys(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('idRoles', 'context')
            ->from('se_permissions')
            ->where('context LIKE :pattern')
            ->setParameter('pattern', self::WEBSPACE_PREFIX . '%' . self::SNIPPET_AREAS_SUFFIX)
            ->executeQuery()
            ->fetchAllAssociative();

        $set = [];
        foreach ($rows as $row) {
            $set[$row['idRoles'] . '|' . $row['context']] = true;
        }

        return $set;
    }
}
