<?php

namespace ClockworkCompanion\Backup;

/**
 * Pure PHP WordPress Database Dumper.
 *
 * Exports all tables and data into a .sql or .sql.gz stream using $wpdb.
 * Safe across all hosting environments (including WP Engine, Pressable, Kinsta)
 * where shell commands / mysqldump binaries may be unavailable or restricted.
 */
class DatabaseDumper
{
    /**
     * Dump all database tables to the specified file path.
     * If the path ends in .gz and zlib is supported, it streams gzipped SQL directly.
     *
     * @return array{ok: bool, tables_count: int, size_bytes: int, error?: string}
     */
    public function dump(string $outputPath): array
    {
        if (! defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }
        if (! defined('ARRAY_N')) {
            define('ARRAY_N', 'ARRAY_N');
        }

        global $wpdb;

        if (! isset($wpdb) || ! is_object($wpdb)) {
            return [
                'ok' => false,
                'tables_count' => 0,
                'size_bytes' => 0,
                'error' => 'WordPress $wpdb is unavailable.',
            ];
        }

        $dir = dirname($outputPath);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return [
                'ok' => false,
                'tables_count' => 0,
                'size_bytes' => 0,
                'error' => "Cannot create backup directory: {$dir}",
            ];
        }

        $useGz = str_ends_with($outputPath, '.gz') && function_exists('gzopen');
        $handle = $useGz ? @gzopen($outputPath, 'wb9') : @fopen($outputPath, 'wb');

        if (! $handle) {
            return [
                'ok' => false,
                'tables_count' => 0,
                'size_bytes' => 0,
                'error' => "Failed to open output file for writing: {$outputPath}",
            ];
        }

        $header = "-- Clockwork Companion Database Backup\n"
            ."-- Generated: ".gmdate('Y-m-d H:i:s')." UTC\n"
            ."-- Database: ".(defined('DB_NAME') ? constant('DB_NAME') : 'wordpress')."\n\n"
            ."SET FOREIGN_KEY_CHECKS=0;\n"
            ."SET SQL_MODE=\"NO_AUTO_VALUE_ON_ZERO\";\n"
            ."SET time_zone = \"+00:00\";\n\n";

        $this->write($handle, $header, $useGz);

        $tables = (array) $wpdb->get_col('SHOW TABLES');
        $tablesCount = count($tables);

        foreach ($tables as $table) {
            $table = (string) $table;
            if ($table === '') {
                continue;
            }

            $createRow = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            $createSql = $createRow[1] ?? null;

            if ($createSql) {
                $tableHeader = "\n-- Table structure for `{$table}`\n"
                    ."DROP TABLE IF EXISTS `{$table}`;\n"
                    .$createSql.";\n\n";
                $this->write($handle, $tableHeader, $useGz);
            }

            // Dump rows in batches of 500
            $totalRows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            if ($totalRows === 0) {
                continue;
            }

            $this->write($handle, "-- Dumping data for `{$table}`\n", $useGz);
            $batchSize = 500;

            for ($offset = 0; $offset < $totalRows; $offset += $batchSize) {
                $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT {$offset}, {$batchSize}", ARRAY_A);
                if (empty($rows)) {
                    break;
                }

                $inserts = [];
                foreach ($rows as $row) {
                    $fields = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $fields[] = 'NULL';
                        } elseif (is_int($val) || is_float($val)) {
                            $fields[] = $val;
                        } else {
                            $escaped = function_exists('esc_sql') ? esc_sql($val) : addslashes($val);
                            $fields[] = "'".$escaped."'";
                        }
                    }
                    $inserts[] = '('.implode(', ', $fields).')';
                }

                if (! empty($inserts)) {
                    $insertSql = "INSERT INTO `{$table}` VALUES \n".implode(",\n", $inserts).";\n";
                    $this->write($handle, $insertSql, $useGz);
                }
            }
        }

        $footer = "\nSET FOREIGN_KEY_CHECKS=1;\n-- Dump completed\n";
        $this->write($handle, $footer, $useGz);

        if ($useGz) {
            gzclose($handle);
        } else {
            fclose($handle);
        }

        return [
            'ok' => true,
            'tables_count' => $tablesCount,
            'size_bytes' => (int) @filesize($outputPath),
        ];
    }

    private function write($handle, string $data, bool $useGz): void
    {
        if ($useGz) {
            gzwrite($handle, $data);
        } else {
            fwrite($handle, $data);
        }
    }
}
