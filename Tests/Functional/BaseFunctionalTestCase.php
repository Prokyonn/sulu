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
use Sulu\Bundle\PhpcrMigrationBundle\Tests\Application\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base test case for functional tests using Symfony kernel.
 *
 * Provides:
 * - Symfony kernel with full DI container
 * - Test fixture loaded into database
 * - Access to all migration services via container
 */
abstract class BaseFunctionalTestCase extends KernelTestCase
{
    protected Connection $targetConnection;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Boot kernel
        self::bootKernel();

        // Get DBAL connection from container
        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->targetConnection = $connection;

        // Load test fixture into database
        TestConnectionFactory::loadFixture($connection);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
