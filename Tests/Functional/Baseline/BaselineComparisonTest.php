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

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Functional\Baseline;

use Doctrine\DBAL\Connection;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\UserInterface\Command\MigratePhpcrCommand;
use Sulu\Bundle\PhpcrMigrationBundle\Tests\Application\Kernel;
use Sulu\Bundle\PhpcrMigrationBundle\Tests\Functional\Helper\JsonBaselineExporter;
use Sulu\Bundle\PhpcrMigrationBundle\Tests\Functional\TestConnectionFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @group optional
 */
class BaselineComparisonTest extends KernelTestCase
{
    private const BASELINE_DIR = __DIR__ . '/../../Resources/baselines';

    private const PAGE_TABLES = [
        'pa_pages',
        'pa_page_dimension_contents',
        'pa_page_dimension_content_excerpt_categories',
        'pa_page_dimension_content_excerpt_tags',
        'pa_page_dimension_content_navigation_contexts',
    ];

    private const ARTICLE_TABLES = [
        'ar_articles',
        'ar_article_dimension_contents',
        'ar_article_dimension_content_additional_webspaces',
        'ar_article_dimension_content_excerpt_categories',
        'ar_article_dimension_content_excerpt_tags',
    ];

    private const SNIPPET_TABLES = [
        'sn_snippets',
        'sn_snippet_dimension_contents',
        'sn_snippet_dimension_content_excerpt_categories',
        'sn_snippet_dimension_content_excerpt_tags',
        'sn_snippet_area',
    ];

    private const CUSTOM_URL_TABLES = [
        'cu_custom_url',
        'cu_custom_url_route',
    ];

    private static ?string $tempDir = null;
    private static bool $baselinesGenerated = false;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');

        TestConnectionFactory::loadFixture($connection);

        self::runMigrationAndExport($connection);

        $baselineFiles = \glob(self::BASELINE_DIR . '/*.json');
        if (false === $baselineFiles || [] === $baselineFiles) {
            self::generateBaselines($connection);
            self::$baselinesGenerated = true;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$baselinesGenerated) {
            $this->markTestSkipped('Baselines were generated. Re-run tests to validate against baselines.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$tempDir && \is_dir(self::$tempDir)) {
            $files = \glob(self::$tempDir . '/*');
            if (false !== $files) {
                \array_map('unlink', $files);
            }
            \rmdir(self::$tempDir);
        }

        self::$tempDir = null;
        self::$baselinesGenerated = false;

        parent::tearDownAfterClass();
    }

    private static function runMigrationAndExport(Connection $connection): void
    {
        $command = self::getContainer()->get(MigratePhpcrCommand::class);
        \assert($command instanceof MigratePhpcrCommand);

        $input = new ArrayInput([
            'documentTypes' => 'page,article,snippet,custom_url,snippet_area',
        ]);
        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        if (0 !== $exitCode) {
            throw new \RuntimeException('Migration command failed: ' . $output->fetch());
        }

        self::$tempDir = \sys_get_temp_dir() . '/baseline_test_' . \uniqid();
        \mkdir(self::$tempDir, 0755, true);
        $exporter = new JsonBaselineExporter($connection, self::$tempDir);
        $exporter->export();
    }

    private static function generateBaselines(Connection $connection): void
    {
        if (!\is_dir(self::BASELINE_DIR)) {
            \mkdir(self::BASELINE_DIR, 0755, true);
        }

        $exporter = new JsonBaselineExporter($connection, self::BASELINE_DIR);
        $exporter->export();
    }

    public function testPageTablesMatchBaseline(): void
    {
        $this->assertTablesMatchBaseline(self::PAGE_TABLES);
    }

    public function testArticleTablesMatchBaseline(): void
    {
        $this->assertTablesMatchBaseline(self::ARTICLE_TABLES);
    }

    public function testSnippetTablesMatchBaseline(): void
    {
        $this->assertTablesMatchBaseline(self::SNIPPET_TABLES);
    }

    public function testCustomUrlTablesMatchBaseline(): void
    {
        $this->assertTablesMatchBaseline(self::CUSTOM_URL_TABLES);
    }

    public function testRemainingTablesMatchBaseline(): void
    {
        $remainingTables = $this->getRemainingTables();
        $this->assertTablesMatchBaseline($remainingTables);
    }

    private const EXCLUDED_FIELDS = ['_id', 'uuid'];

    /**
     * @param list<string> $tables
     */
    private function assertTablesMatchBaseline(array $tables): void
    {
        if (null === self::$tempDir) {
            $this->fail('Temp directory not initialized. Migration may have failed.');
        }

        foreach ($tables as $table) {
            $baselineFile = self::BASELINE_DIR . '/' . $table . '.json';
            $actualFile = self::$tempDir . '/' . $table . '.json';

            if (!\file_exists($baselineFile)) {
                $this->fail("Baseline not found for table '{$table}'. Re-run tests to generate baselines.");
            }

            if (!\file_exists($actualFile)) {
                $this->fail("Table '{$table}' was not exported. Check if the table exists in the database.");
            }

            $expected = $this->loadJson($baselineFile);
            $actual = $this->loadJson($actualFile);

            $this->assertSame(
                $expected,
                $actual,
                \sprintf(
                    "Table '%s' does not match baseline.\n\nTo update baselines, delete Tests/Resources/baselines/*.json and re-run tests.",
                    $table
                )
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadJson(string $path): array
    {
        $content = \file_get_contents($path);
        if (false === $content) {
            return [];
        }

        $data = \json_decode($content, true);
        if (!\is_array($data)) {
            return [];
        }

        $rows = \array_map(
            fn (array $row): array => $this->removeExcludedFields($row),
            $data
        );

        \usort($rows, fn (array $a, array $b): int => \serialize($a) <=> \serialize($b));

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function removeExcludedFields(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (\in_array($key, self::EXCLUDED_FIELDS, true)) {
                continue;
            }
            if (\is_array($value)) {
                $result[$key] = $this->removeExcludedFields($value);
            } elseif (\is_string($value) && \str_starts_with($value, '{')) {
                $decoded = \json_decode($value, true);
                if (\is_array($decoded)) {
                    $cleaned = $this->removeExcludedFields($decoded);
                    $result[$key] = \json_encode($cleaned, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                } else {
                    $result[$key] = $value;
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function getRemainingTables(): array
    {
        $groupedTables = \array_merge(
            self::PAGE_TABLES,
            self::ARTICLE_TABLES,
            self::SNIPPET_TABLES,
            self::CUSTOM_URL_TABLES
        );

        $baselineFiles = \glob(self::BASELINE_DIR . '/*.json');
        if (false === $baselineFiles) {
            return [];
        }

        $remaining = [];
        foreach ($baselineFiles as $file) {
            $table = \pathinfo($file, \PATHINFO_FILENAME);
            if (!\in_array($table, $groupedTables, true)) {
                $remaining[] = $table;
            }
        }

        \sort($remaining);

        return $remaining;
    }
}
