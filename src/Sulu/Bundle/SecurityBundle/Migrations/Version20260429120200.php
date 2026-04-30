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

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove legacy 2.x list-store user settings. Some referenced columns are no longer present in
 * the 3.x schema and retaining outdated entries can cause exceptions in the admin UI.
 *
 * Naturally idempotent: re-running matches zero rows.
 */
final class Version20260429120200 extends AbstractMigration
{
    /**
     * @var list<string>
     */
    private const PREFIXES = [
        'sulu_admin.list_store.articles',
        'sulu_admin.list_store.snippets',
        'sulu_admin.list_store.pages',
    ];

    public function getDescription(): string
    {
        return 'Remove legacy list-store user settings entries (sulu_admin.list_store.articles|snippets|pages*)';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('se_user_settings')) {
            return;
        }

        foreach (self::PREFIXES as $prefix) {
            $this->connection->createQueryBuilder()
                ->delete('se_user_settings')
                ->where('settingsKey LIKE :pattern')
                ->setParameter('pattern', $prefix . '%')
                ->executeStatement();
        }
    }

    public function down(Schema $schema): void
    {
        // Deleted user settings cannot be reconstructed.
    }
}
