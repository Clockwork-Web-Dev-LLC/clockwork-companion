<?php

namespace ClockworkCompanion\Backup;

class RestoreState
{
    public const OPTION_KEY = 'clockwork_companion_restore_state';

    public static function get(): array
    {
        $stored = get_option(self::OPTION_KEY);
        if (! is_array($stored)) {
            return [
                'staged_id' => null,
                'phase' => null,
                'status' => 'idle',
                'archive_key' => null,
                'expected_sha256' => null,
                'actual_sha256' => null,
                'hash_verified' => false,
                'bytes_total' => 0,
                'bytes_done' => 0,
                'has_sql' => false,
                'has_files' => false,
                'table_prefix' => null,
                'skipped_tables' => 0,
                'skipped_statements' => 0,
                'error' => null,
                'error_detail' => null,
                'updated_at' => null,
            ];
        }

        return $stored;
    }

    public static function update(array $patch): array
    {
        $current = self::get();
        $updated = array_merge($current, $patch, [
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        update_option(self::OPTION_KEY, $updated, false);

        return $updated;
    }

    public static function reset(): void
    {
        delete_option(self::OPTION_KEY);
    }
}
