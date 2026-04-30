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

namespace Sulu\Content\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Convert SmartContent stored "tags" arrays in templateData JSON columns from tag names to tag IDs.
 *
 * SmartContent previously persisted its tag filter as an array of tag name strings; it now
 * persists tag ID integers. The migration walks templateData JSON, identifies smart content
 * configurations by the presence of a `tagOperator` sibling key, and replaces each tag name with
 * the corresponding `ta_tags.id` value. Strings that do not resolve to a known tag are left
 * untouched.
 */
final class Version20260429120000 extends AbstractMigration
{
    private const TARGET_TABLES = [
        'pa_page_dimension_contents',
        'ar_article_dimension_contents',
        'sn_snippet_dimension_contents',
    ];

    private const TAGS_TABLE = 'ta_tags';

    /**
     * @var array<array-key, scalar>
     */
    private array $tagMap = [];

    private bool $reverseMapping = false;

    public function getDescription(): string
    {
        return 'Convert SmartContent stored tags from tag names to tag IDs in templateData JSON';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable(self::TAGS_TABLE)) {
            return;
        }

        $this->tagMap = $this->buildTagMap('name', 'id');
        $this->reverseMapping = false;

        if ([] === $this->tagMap) {
            return;
        }

        $this->convertAllTables($schema);
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable(self::TAGS_TABLE)) {
            return;
        }

        $this->tagMap = $this->buildTagMap('id', 'name');
        $this->reverseMapping = true;

        if ([] === $this->tagMap) {
            return;
        }

        $this->convertAllTables($schema);
    }

    /**
     * @return array<array-key, scalar>
     */
    private function buildTagMap(string $keyColumn, string $valueColumn): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select($keyColumn, $valueColumn)
            ->from(self::TAGS_TABLE)
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $map[$row[$keyColumn]] = $row[$valueColumn];
        }

        return $map;
    }

    private function convertAllTables(Schema $schema): void
    {
        foreach (self::TARGET_TABLES as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }
            $this->convertTable($table);
        }
    }

    private function convertTable(string $table): void
    {
        $result = $this->connection->createQueryBuilder()
            ->select('id', 'templateData AS template_data')
            ->from($table)
            ->executeQuery();

        foreach ($result->iterateAssociative() as $row) {
            $raw = $row['template_data'] ?? null;
            if (!\is_string($raw) || '' === $raw) {
                continue;
            }

            $decoded = \json_decode($raw, true);
            if (!\is_array($decoded)) {
                continue;
            }

            $changed = false;
            $converted = $this->convertSmartContentTags($decoded, $changed);
            if (!$changed) {
                continue;
            }

            $this->connection->createQueryBuilder()
                ->update($table)
                ->set('templateData', ':template_data')
                ->where('id = :id')
                ->setParameter('template_data', $converted, Types::JSON)
                ->setParameter('id', $row['id'])
                ->executeStatement();
        }
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function convertSmartContentTags(array $data, bool &$changed): array
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = $this->convertSmartContentTags($value, $changed);
            }
        }

        if (
            !\array_key_exists('tagOperator', $data)
            || !\array_key_exists('tags', $data)
            || !\is_array($data['tags'])
        ) {
            return $data;
        }

        $newTags = [];
        foreach ($data['tags'] as $tag) {
            $mapped = $this->mapTag($tag);
            if (null === $mapped) {
                $newTags[] = $tag;
                continue;
            }
            if ($mapped !== $tag) {
                $changed = true;
            }
            $newTags[] = $mapped;
        }

        $data['tags'] = $newTags;

        return $data;
    }

    private function mapTag(mixed $tag): int|string|null
    {
        if ($this->reverseMapping) {
            if (!\is_int($tag) && !(\is_string($tag) && \ctype_digit($tag))) {
                return null;
            }
            $key = (int) $tag;

            return isset($this->tagMap[$key]) ? (string) $this->tagMap[$key] : null;
        }

        if (!\is_string($tag)) {
            return null;
        }

        return isset($this->tagMap[$tag]) ? (int) $this->tagMap[$tag] : null;
    }
}
