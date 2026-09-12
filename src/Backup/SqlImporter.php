<?php

namespace ClockworkCompanion\Backup;

/**
 * Streams and executes statements from a .sql or .sql.gz dump file.
 * Scope: current-prefix tables only ($wpdb->prefix), passing through SET statements.
 * Fails closed on prefix mismatch (if zero tables match $wpdb->prefix).
 */
class SqlImporter
{
    /** @var callable|null Test seam: fn(string $sqlFilePath, string $tablePrefix): array{ok: bool, error?: string, detail?: string, matched_tables: int, skipped_tables: int} */
    public static $testImporter = null;

    /**
     * Stream and execute SQL statements from a .sql or .sql.gz dump file.
     *
     * @param  string  $sqlFilePath Path to the dump file.
     * @param  string|null  $prefix Table prefix (defaults to $wpdb->prefix).
     * @return array{ok: bool, error?: string, detail?: string, matched_tables: int, skipped_tables: int}
     */
    public function import(string $sqlFilePath, ?string $prefix = null): array
    {
        global $wpdb;

        if (! file_exists($sqlFilePath)) {
            return [
                'ok' => false,
                'error' => 'sql_failed',
                'detail' => "SQL dump file does not exist: {$sqlFilePath}",
                'matched_tables' => 0,
                'skipped_tables' => 0,
            ];
        }

        $prefix = $prefix !== null ? $prefix : (isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_');

        if (is_callable(self::$testImporter)) {
            $res = call_user_func(self::$testImporter, $sqlFilePath, $prefix);
            if (is_array($res)) {
                return $res;
            }

            return [
                'ok' => (bool) $res,
                'matched_tables' => 1,
                'skipped_tables' => 0,
            ];
        }

        $isGz = str_ends_with($sqlFilePath, '.gz') && function_exists('gzopen');
        $handle = $isGz ? @gzopen($sqlFilePath, 'rb') : @fopen($sqlFilePath, 'rb');

        if (! $handle) {
            return [
                'ok' => false,
                'error' => 'sql_failed',
                'detail' => "Failed to open SQL file: {$sqlFilePath}",
                'matched_tables' => 0,
                'skipped_tables' => 0,
            ];
        }

        $matchedTables = [];
        $skippedTables = [];
        $buffer = '';

        while (! ($isGz ? gzeof($handle) : feof($handle))) {
            $line = $isGz ? gzgets($handle, 65536) : fgets($handle, 65536);
            if ($line === false) {
                break;
            }

            $trimmedLine = trim($line);
            if ($trimmedLine === '' || str_starts_with($trimmedLine, '--') || str_starts_with($trimmedLine, '/*')) {
                continue;
            }

            $buffer .= $line;

            if (str_ends_with(rtrim($line), ';')) {
                $statement = trim($buffer);
                $buffer = '';

                if ($statement === '') {
                    continue;
                }

                if (preg_match('/^SET\s+/i', $statement)) {
                    if (isset($wpdb) && is_object($wpdb)) {
                        $wpdb->query($statement);
                    }
                    continue;
                }

                if (preg_match('/^(?:DROP TABLE(?:\s+IF EXISTS)?|CREATE TABLE(?:\s+IF NOT EXISTS)?|INSERT INTO)\s+[`"]?([^`"\s]+)[`"]?/i', $statement, $matches)) {
                    $table = $matches[1];
                    if (str_starts_with($table, $prefix)) {
                        $matchedTables[$table] = true;
                        if (isset($wpdb) && is_object($wpdb)) {
                            $res = $wpdb->query($statement);
                            if ($res === false) {
                                if ($isGz) {
                                    gzclose($handle);
                                } else {
                                    fclose($handle);
                                }

                                return [
                                    'ok' => false,
                                    'error' => 'sql_failed',
                                    'detail' => $wpdb->last_error ?: "Failed executing statement on table {$table}",
                                    'matched_tables' => count($matchedTables),
                                    'skipped_tables' => count($skippedTables),
                                ];
                            }
                        }
                    } else {
                        $skippedTables[$table] = true;
                    }
                } else {
                    if (isset($wpdb) && is_object($wpdb)) {
                        $wpdb->query($statement);
                    }
                }
            }
        }

        if ($isGz) {
            gzclose($handle);
        } else {
            fclose($handle);
        }

        if (count($matchedTables) === 0) {
            return [
                'ok' => false,
                'error' => 'prefix_mismatch',
                'detail' => 'None of the tables in the backup archive matched the current WordPress prefix ('.$prefix.').',
                'matched_tables' => 0,
                'skipped_tables' => count($skippedTables),
            ];
        }

        return [
            'ok' => true,
            'matched_tables' => count($matchedTables),
            'skipped_tables' => count($skippedTables),
        ];
    }
}
