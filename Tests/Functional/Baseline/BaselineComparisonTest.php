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

use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\UserInterface\Command\MigratePhpcrCommand;
use Sulu\Bundle\PhpcrMigrationBundle\Tests\Functional\BaseFunctionalTestCase;
use Sulu\Bundle\PhpcrMigrationBundle\Tests\Functional\Helper\CsvBaselineExporter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Compares migration output against committed CSV baselines.
 *
 * Tests are grouped by content type for cleaner output.
 * First run: Automatically generates baselines if missing.
 * Subsequent runs: Compares migration output against baselines.
 *
 * @group optional
 */
class BaselineComparisonTest extends BaseFunctionalTestCase
{
    private const BASELINE_DIR = __DIR__ . '/../../Resources/baselines';

    /**
     * Page tables from ContentBundle.
     */
    private const PAGE_TABLES = [
        'pa_pages',
        'pa_page_dimension_contents',
        'pa_page_dimension_content_excerpt_categories',
        'pa_page_dimension_content_excerpt_tags',
        'pa_page_dimension_content_navigation_contexts',
    ];

    /**
     * Article tables from ContentBundle.
     */
    private const ARTICLE_TABLES = [
        'ar_articles',
        'ar_article_dimension_contents',
        'ar_article_dimension_content_additional_webspaces',
        'ar_article_dimension_content_excerpt_categories',
        'ar_article_dimension_content_excerpt_tags',
    ];

    /**
     * Snippet tables from ContentBundle.
     */
    private const SNIPPET_TABLES = [
        'sn_snippets',
        'sn_snippet_dimension_contents',
        'sn_snippet_dimension_content_excerpt_categories',
        'sn_snippet_dimension_content_excerpt_tags',
        'sn_snippet_area',
    ];

    /**
     * Custom URL tables.
     */
    private const CUSTOM_URL_TABLES = [
        'cu_custom_url',
        'cu_custom_url_route',
    ];

    private static bool $migrationExecuted = false;
    private static ?string $tempDir = null;

    private static bool $baselinesGenerated = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Run migration once for all tests
        if (!self::$migrationExecuted) {
            $this->runFullMigration();
            self::$migrationExecuted = true;

            // Export current state to temp directory for comparison
            self::$tempDir = \sys_get_temp_dir() . '/baseline_test_' . \uniqid();
            \mkdir(self::$tempDir, 0755, true);
            $exporter = new CsvBaselineExporter($this->targetConnection, self::$tempDir);
            $exporter->export();

            // Check if baselines exist, generate if not
            $baselineFiles = \glob(self::BASELINE_DIR . '/*.csv');
            if (false === $baselineFiles || [] === $baselineFiles) {
                $this->generateBaselines();
                self::$baselinesGenerated = true;
            }
        }

        // Skip all tests if baselines were just generated
        if (self::$baselinesGenerated) {
            $this->markTestSkipped('Baselines were generated. Re-run tests to validate against baselines.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        // Cleanup temp directory
        if (null !== self::$tempDir && \is_dir(self::$tempDir)) {
            $files = \glob(self::$tempDir . '/*');
            if (false !== $files) {
                \array_map('unlink', $files);
            }
            \rmdir(self::$tempDir);
        }

        self::$migrationExecuted = false;
        self::$tempDir = null;
        self::$baselinesGenerated = false;

        parent::tearDownAfterClass();
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

    /**
     * Assert that all specified tables match their baselines.
     *
     * @param list<string> $tables
     */
    private function assertTablesMatchBaseline(array $tables): void
    {
        if (null === self::$tempDir) {
            $this->fail('Temp directory not initialized. Migration may have failed.');
        }

        foreach ($tables as $table) {
            $baselineFile = self::BASELINE_DIR . '/' . $table . '.csv';
            $actualFile = self::$tempDir . '/' . $table . '.csv';

            if (!\file_exists($baselineFile)) {
                $this->fail("Baseline not found for table '{$table}'. Re-run tests to generate baselines.");
            }

            if (!\file_exists($actualFile)) {
                $this->fail("Table '{$table}' was not exported. Check if the table exists in the database.");
            }

            $expected = \file_get_contents($baselineFile);
            $actual = \file_get_contents($actualFile);

            if (false === $expected || false === $actual) {
                $this->fail("Failed to read files for table '{$table}'.");
            }

            $this->assertEquals(
                $expected,
                $actual,
                \sprintf(
                    "Table '%s' does not match baseline.\n\nTo update baselines, delete Tests/Resources/baselines/*.csv and re-run tests.\n\nDiff:\n%s",
                    $table,
                    $this->generateDiff($baselineFile, $actualFile)
                )
            );
        }
    }

    /**
     * Get tables that aren't in any specific group.
     *
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

        $baselineFiles = \glob(self::BASELINE_DIR . '/*.csv');
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

    private function generateBaselines(): void
    {
        if (!\is_dir(self::BASELINE_DIR)) {
            \mkdir(self::BASELINE_DIR, 0755, true);
        }

        $exporter = new CsvBaselineExporter($this->targetConnection, self::BASELINE_DIR);
        $exporter->export();
    }

    private function runFullMigration(): void
    {
        /** @var MigratePhpcrCommand $command */
        $command = self::getContainer()->get(MigratePhpcrCommand::class);

        $input = new ArrayInput([
            'documentTypes' => 'page,article,snippet,custom_url,snippet_area',
        ]);
        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        if (0 !== $exitCode) {
            $this->fail('Migration command failed: ' . $output->fetch());
        }
    }

    private function generateDiff(string $expectedPath, string $actualPath): string
    {
        $expected = \file($expectedPath, \FILE_IGNORE_NEW_LINES) ?: [];
        $actual = \file($actualPath, \FILE_IGNORE_NEW_LINES) ?: [];

        $diff = [];
        $max = \max(\count($expected), \count($actual));
        $diffCount = 0;

        for ($i = 0; $i < $max && $diffCount < 10; ++$i) {
            $expectedLine = $expected[$i] ?? '<missing>';
            $actualLine = $actual[$i] ?? '<missing>';

            if ($expectedLine !== $actualLine) {
                $diff[] = '  Line ' . ($i + 1) . ':';
                $diff[] = "    - {$expectedLine}";
                $diff[] = "    + {$actualLine}";
                ++$diffCount;
            }
        }

        if ($max > \count($diff) / 3 + $diffCount) {
            $remaining = $max - $i;
            if ($remaining > 0) {
                $diff[] = "  ... and possibly {$remaining} more lines with differences";
            }
        }

        return \implode("\n", $diff);
    }
}
