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

namespace Sulu\Bundle\PhpcrMigrationBundle\Tests\Functional\Helper;

use Doctrine\DBAL\Connection;

/**
 * Exports database tables to CSV for baseline comparison.
 *
 * CSV files are deterministic: rows sorted by primary key, consistent formatting.
 */
class CsvBaselineExporter
{
    /**
     * System table prefixes to exclude from export.
     */
    private const EXCLUDED_PREFIXES = [
        'phpcr_',
    ];

    /**
     * System table names to exclude from export.
     */
    private const EXCLUDED_TABLES = [
        'doctrine_migration_versions',
        'migration_versions',
        'messenger_messages',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $outputDir,
    ) {
    }

    /**
     * Export all tables to CSV files.
     *
     * @return int Number of tables exported
     */
    public function export(): int
    {
        if (!\is_dir($this->outputDir)) {
            \mkdir($this->outputDir, 0755, true);
        }

        $tables = $this->getExportTables();

        foreach ($tables as $table) {
            $this->exportTable($table);
        }

        return \count($tables);
    }

    private function exportTable(string $table): int
    {
        // Get primary key for sorting
        $pkResult = $this->connection->fetchAllAssociative(
            "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'"
        );
        $pkColumns = \array_column($pkResult, 'Column_name');
        $orderBy = [] === $pkColumns ? '' : ' ORDER BY ' . \implode(', ', $pkColumns);

        // Fetch data
        $rows = $this->connection->fetchAllAssociative("SELECT * FROM `{$table}`{$orderBy}");

        // Get column names
        $columns = $this->connection->fetchAllAssociative("SHOW COLUMNS FROM `{$table}`");
        $columnFields = \array_column($columns, 'Field');
        /** @var list<string> $columnNames */
        $columnNames = \array_map(
            function(mixed $col): string {
                if (!\is_string($col)) {
                    throw new \RuntimeException('Expected column name to be string');
                }

                return $col;
            },
            $columnFields
        );

        // Write CSV
        $outputPath = $this->outputDir . '/' . $table . '.csv';
        $fp = \fopen($outputPath, 'w');
        if (false === $fp) {
            throw new \RuntimeException("Failed to open: {$outputPath}");
        }

        \fputcsv($fp, $columnNames);

        foreach ($rows as $row) {
            $normalizedRow = \array_map(
                function(mixed $v): string {
                    if (null === $v) {
                        return '';
                    }
                    if (!\is_scalar($v)) {
                        throw new \RuntimeException('Expected scalar value for CSV export');
                    }

                    return (string) $v;
                },
                $row
            );
            \fputcsv($fp, $normalizedRow);
        }

        \fclose($fp);

        return \count($rows);
    }

    /**
     * Get all tables to export (excludes system tables).
     *
     * @return list<string>
     */
    private function getExportTables(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
            [$this->connection->getDatabase()]
        );

        $tables = [];
        foreach ($rows as $row) {
            $tableName = $row['TABLE_NAME'];
            if (!\is_string($tableName)) {
                continue;
            }

            if ($this->shouldExcludeTable($tableName)) {
                continue;
            }

            $tables[] = $tableName;
        }

        return $tables;
    }

    /**
     * Check if table should be excluded from export.
     */
    private function shouldExcludeTable(string $tableName): bool
    {
        // Exclude by exact name
        if (\in_array($tableName, self::EXCLUDED_TABLES, true)) {
            return true;
        }

        // Exclude by prefix
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (\str_starts_with($tableName, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
