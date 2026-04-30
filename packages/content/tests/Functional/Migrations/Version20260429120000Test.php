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

namespace Sulu\Content\Tests\Functional\Migrations;

use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Sulu\Bundle\TagBundle\Entity\Tag;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Sulu\Content\Migrations\Version20260429120000;

/**
 * Targets `sn_snippet_dimension_contents` because snippets have the simplest parent table; the
 * migration applies the same logic to page and article dimension content tables.
 */
class Version20260429120000Test extends SuluTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        self::purgeDatabase();
        $this->connection = self::getEntityManager()->getConnection();
    }

    public function testConvertsTagNamesToTagIdsForSmartContentBlocks(): void
    {
        $mobile = $this->createTagId('mobile');
        $cloud = $this->createTagId('cloud');

        $uuid = $this->seedDimensionContent('00000000-0000-0000-0000-000000000001', [
            'fieldA' => [
                'tags' => ['mobile', 'cloud'],
                'tagOperator' => 'and',
            ],
            'plainText' => 'unrelated',
        ]);

        $this->runMigration();

        $stored = $this->fetchTemplateData($uuid);
        self::assertSame([$mobile, $cloud], $stored['fieldA']['tags']);
        self::assertSame('and', $stored['fieldA']['tagOperator']);
        self::assertSame('unrelated', $stored['plainText']);
    }

    public function testLeavesUnknownTagNamesUntouched(): void
    {
        $mobile = $this->createTagId('mobile');

        $uuid = $this->seedDimensionContent('00000000-0000-0000-0000-000000000002', [
            'field' => [
                'tags' => ['mobile', 'does-not-exist'],
                'tagOperator' => 'or',
            ],
        ]);

        $this->runMigration();

        self::assertSame([$mobile, 'does-not-exist'], $this->fetchTemplateData($uuid)['field']['tags']);
    }

    public function testIgnoresTagsKeyWithoutTagOperatorSibling(): void
    {
        $this->createTagId('mobile');

        $uuid = $this->seedDimensionContent('00000000-0000-0000-0000-000000000003', [
            'customField' => [
                'tags' => ['mobile'],
            ],
        ]);

        $this->runMigration();

        self::assertSame(['mobile'], $this->fetchTemplateData($uuid)['customField']['tags']);
    }

    public function testIsIdempotentWhenAlreadyMigrated(): void
    {
        $mobile = $this->createTagId('mobile');

        $uuid = $this->seedDimensionContent('00000000-0000-0000-0000-000000000004', [
            'field' => [
                'tags' => [$mobile],
                'tagOperator' => 'and',
            ],
        ]);

        $this->runMigration();
        $this->runMigration();

        self::assertSame([$mobile], $this->fetchTemplateData($uuid)['field']['tags']);
    }

    public function testHandlesNestedSmartContentBlocks(): void
    {
        $mobile = $this->createTagId('mobile');

        $uuid = $this->seedDimensionContent('00000000-0000-0000-0000-000000000005', [
            'blocks' => [
                ['type' => 'text', 'body' => 'hello'],
                [
                    'type' => 'teaser',
                    'config' => [
                        'tags' => ['mobile'],
                        'tagOperator' => 'and',
                    ],
                ],
            ],
        ]);

        $this->runMigration();

        self::assertSame([$mobile], $this->fetchTemplateData($uuid)['blocks'][1]['config']['tags']);
    }

    private function runMigration(): void
    {
        $migration = new Version20260429120000($this->connection, new NullLogger());
        $migration->up($this->connection->createSchemaManager()->introspectSchema());
    }

    private function createTagId(string $name): int
    {
        $em = self::getEntityManager();
        $tag = new Tag();
        $tag->setName($name);
        $em->persist($tag);
        $em->flush();

        return (int) $tag->getId();
    }

    /**
     * @param array<string, mixed> $templateData
     */
    private function seedDimensionContent(string $uuid, array $templateData): string
    {
        $this->connection->createQueryBuilder()
            ->insert('sn_snippets')
            ->values(['uuid' => ':uuid'])
            ->setParameter('uuid', $uuid)
            ->executeStatement();

        $this->connection->createQueryBuilder()
            ->insert('sn_snippet_dimension_contents')
            ->values([
                'snippetUuid' => ':uuid',
                'stage' => ':stage',
                'locale' => ':locale',
                'version' => ':version',
                'templateKey' => ':templateKey',
                'templateData' => ':templateData',
            ])
            ->setParameter('uuid', $uuid)
            ->setParameter('stage', 'draft')
            ->setParameter('locale', 'en')
            ->setParameter('version', 1)
            ->setParameter('templateKey', 'default')
            ->setParameter('templateData', \json_encode($templateData, \JSON_THROW_ON_ERROR))
            ->executeStatement();

        return $uuid;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTemplateData(string $uuid): array
    {
        $raw = $this->connection->createQueryBuilder()
            ->select('templateData')
            ->from('sn_snippet_dimension_contents')
            ->where('snippetUuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->executeQuery()
            ->fetchOne();
        self::assertIsString($raw);

        return \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
    }
}
