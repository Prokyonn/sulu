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

namespace Sulu\Bundle\MediaBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MediaType denormalization, step 2 of 2: flips `me_media.type` to NOT NULL, adds its index,
 * drops the legacy `me_media.idMediaTypes` column and the `me_media_types` table.
 */
final class Version20260429120500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MediaType denormalization (2/2): drop me_media_types and me_media.idMediaTypes, set me_media.type NOT NULL, index it';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('me_media')) {
            return;
        }

        $mediaTable = $schema->getTable('me_media');

        if ($mediaTable->hasColumn('type')) {
            $typeColumn = $mediaTable->getColumn('type');
            if (false === $typeColumn->getNotnull()) {
                $typeColumn->setNotnull(true);
            }

            if (!$this->hasIndexOnColumns($schema, 'me_media', ['type'])) {
                $mediaTable->addIndex(['type']);
            }
        }

        if ($mediaTable->hasColumn('idMediaTypes')) {
            $mediaTable->dropColumn('idMediaTypes');
        }

        if ($schema->hasTable('me_media_types')) {
            $schema->dropTable('me_media_types');
        }
    }

    public function down(Schema $schema): void
    {
        // me_media_types rows and their auto-increment ids are gone; the idMediaTypes FK cannot be reconstructed.
    }

    /**
     * @param list<string> $columns
     */
    private function hasIndexOnColumns(Schema $schema, string $tableName, array $columns): bool
    {
        $table = $schema->getTable($tableName);
        $needle = \array_map('strtolower', $columns);

        foreach ($table->getIndexes() as $index) {
            $haystack = \array_map('strtolower', $index->getColumns());
            if ($haystack === $needle) {
                return true;
            }
        }

        return false;
    }
}
