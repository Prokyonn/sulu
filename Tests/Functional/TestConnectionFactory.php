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

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Sulu\Bundle\PhpcrMigrationBundle\Tests\Functional\Fixture\TestFixtureBuilder;

/**
 * Simplified factory for loading test fixtures.
 *
 * Note: Connections now come from Symfony container.
 * This class only handles fixture loading.
 */
final class TestConnectionFactory
{
    private const FIXTURES_DIR = __DIR__ . '/../Resources/fixtures';

    private static bool $fixtureLoaded = false;

    /**
     * Load test fixture into database.
     *
     * Automatically rebuilds the fixture if source files have changed.
     * Requires a DBAL connection from the container.
     */
    public static function loadFixture(Connection $connection): void
    {
        if (self::$fixtureLoaded) {
            return;
        }

        $builder = new TestFixtureBuilder(self::FIXTURES_DIR);

        if ($builder->needsRebuild()) {
            $builder->build();
        }

        $fixturesPath = $builder->getOutputPath();
        if (!\file_exists($fixturesPath)) {
            throw new \RuntimeException("Test fixture not found: {$fixturesPath}\nEnsure sulu26_dump.sql and sulu30_schema.sql exist in Tests/Resources/fixtures/");
        }

        // Get database name from connection params (without connecting)
        $params = $connection->getParams();
        $dbName = $params['dbname'] ?? throw new \RuntimeException('Database name not found in connection params');

        // Create a temporary connection without database selection to create/drop the database
        $tempParams = $params;
        unset($tempParams['dbname']);
        $tempConnection = DriverManager::getConnection($tempParams);

        // Drop and recreate database for clean state
        $tempConnection->executeStatement("DROP DATABASE IF EXISTS `{$dbName}`");
        $tempConnection->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $tempConnection->close();

        // Now use the regular connection (which has the database specified) for loading fixture
        $connection->executeStatement("USE `{$dbName}`");

        // Load fixture
        $sql = \file_get_contents($fixturesPath);
        if (false === $sql) {
            throw new \RuntimeException('Failed to read fixture file');
        }

        $connection->executeStatement($sql);
        self::$fixtureLoaded = true;
    }
}
