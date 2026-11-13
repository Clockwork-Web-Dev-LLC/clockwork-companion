<?php

namespace ClockworkCompanion\Tests\Fakes;

/**
 * In-memory stand-in for the Gatekeeper lockout table(s).
 *
 * prepare() remembers the template and arguments behind every prepared
 * string, and query()/get_*() dispatch on the template, emulating the MySQL
 * semantics Store and Schema rely on: INSERT ... ON DUPLICATE KEY UPDATE with
 * LAST_INSERT_ID(expr), rows_affected/insert_id, left-to-right SET clauses,
 * INSERT IGNORE on the unique ip key. Anything else is delegated to the
 * shared \wpdb stub from tests/bootstrap.php. (Wrapped rather than extended:
 * a failed query has to be able to return false.)
 */
class GatekeeperWpdb
{
    public string $prefix = 'wp_';

    public string $options = 'wp_options';

    public string $sitemeta = 'wp_sitemeta';

    public string $last_error = '';

    /** @var list<string> */
    public array $queries = [];

    private \wpdb $inner;

    public function __construct()
    {
        $this->inner = new \wpdb();
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->inner->{$name}(...$arguments);
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public string $base_prefix = 'wp_';

    public int $rows_affected = 0;

    public int $insert_id = 0;

    /** @var array<string, array<string, array<string, mixed>>> table => ip => row */
    public array $lockoutTables = ['wp_clockwork_lockouts' => []];

    /** Simulate a database without LAST_INSERT_ID(expr) support. */
    public bool $rejectLastInsertId = false;

    /** @var array<string, array{0: string, 1: array<int, mixed>}> */
    private array $prepared = [];

    private int $nextId = 1;

    public function get_blog_prefix(?int $blogId = null): string
    {
        return ($blogId === null || $blogId === 1) ? $this->base_prefix : $this->base_prefix . $blogId . '_';
    }

    /** @return array<string, array<string, mixed>> */
    public function rows(string $table = 'wp_clockwork_lockouts'): array
    {
        return $this->lockoutTables[$table] ?? [];
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $sql = $this->inner->prepare($query, ...$args);
        $this->prepared[$sql] = [$query, $args];

        return $sql;
    }

    public function query(string $query): int|false
    {
        $this->queries[] = $query;
        [$template, $args] = $this->prepared[$query] ?? [$query, []];

        if (preg_match('/^DROP TABLE IF EXISTS `([^`]+)`/', $query, $m)) {
            unset($this->lockoutTables[$m[1]]);

            return 1;
        }

        $table = $this->tableIn($template);
        if ($table === null) {
            return (int) $this->inner->query($query);
        }

        $rows = &$this->lockoutTables[$table];
        $rows ??= [];

        if (str_contains($template, 'ON DUPLICATE KEY UPDATE')) {
            if ($this->rejectLastInsertId && str_contains($template, 'LAST_INSERT_ID')) {
                return false;
            }
            [$ip, $start, $created, $updated, $cutoffA, $cutoffB, $nowB, $nowC] = $args;
            if (! isset($rows[$ip])) {
                $rows[$ip] = [
                    'id' => $this->nextId++, 'ip' => $ip, 'unlock_at' => null, 'consecutive_lockouts' => 0,
                    'attempts' => 1, 'attempts_window_start' => $start, 'created_at' => $created, 'updated_at' => $updated,
                ];
                $this->rows_affected = 1;
                $this->insert_id = $rows[$ip]['id'];

                return 1;
            }
            $row = &$rows[$ip];
            $inWindow = $row['attempts_window_start'] !== null && $row['attempts_window_start'] > $cutoffA;
            $row['attempts'] = $inWindow ? $row['attempts'] + 1 : 1;
            $inWindowB = $row['attempts_window_start'] !== null && $row['attempts_window_start'] > $cutoffB;
            $row['attempts_window_start'] = $inWindowB ? $row['attempts_window_start'] : $nowB;
            $row['updated_at'] = $nowC;
            $this->rows_affected = 2;
            $this->insert_id = str_contains($template, 'LAST_INSERT_ID') ? $row['attempts'] : 0;

            return 2;
        }

        if (str_contains($template, 'SET attempts = attempts - 1')) {
            [$ip] = $args;
            if (isset($rows[$ip]) && $rows[$ip]['attempts'] > 0) {
                $rows[$ip]['attempts']--;

                return $this->rows_affected = 1;
            }

            return $this->rows_affected = 0;
        }

        if (str_contains($template, 'consecutive_lockouts = IF(')) {
            [$decayCutoff, $extendedAt, $extendedUntil, $normalUntil, $now, $ip, $nowB] = $args;
            if (! isset($rows[$ip]) || ($rows[$ip]['unlock_at'] !== null && $rows[$ip]['unlock_at'] > $nowB)) {
                return $this->rows_affected = 0;
            }
            $row = &$rows[$ip];
            $keep = $row['unlock_at'] !== null && $row['unlock_at'] > $decayCutoff;
            $row['consecutive_lockouts'] = ($keep ? (int) $row['consecutive_lockouts'] : 0) + 1;
            $row['unlock_at'] = $row['consecutive_lockouts'] >= (int) $extendedAt ? $extendedUntil : $normalUntil;
            $row['attempts'] = 0;
            $row['updated_at'] = $now;

            return $this->rows_affected = 1;
        }

        if (str_starts_with(ltrim($template), 'INSERT IGNORE INTO') && str_contains($template, 'SELECT ip, unlock_at')) {
            [$now] = $args;
            $source = $this->tableIn(substr($template, strpos($template, 'SELECT')));
            $copied = 0;
            foreach ($this->lockoutTables[$source] ?? [] as $ip => $row) {
                if ($row['unlock_at'] !== null && $row['unlock_at'] > $now && ! isset($rows[$ip])) {
                    $rows[$ip] = array_merge($row, ['id' => $this->nextId++, 'attempts' => 0, 'attempts_window_start' => null]);
                    $copied++;
                }
            }

            return $this->rows_affected = $copied;
        }

        if (str_starts_with(ltrim($template), 'INSERT IGNORE INTO')) {
            [$ip, $unlockAt, $created, $updated] = $args;
            if (isset($rows[$ip])) {
                return $this->rows_affected = 0;
            }
            $rows[$ip] = [
                'id' => $this->nextId++, 'ip' => $ip, 'unlock_at' => $unlockAt, 'consecutive_lockouts' => 1,
                'attempts' => 0, 'attempts_window_start' => null, 'created_at' => $created, 'updated_at' => $updated,
            ];

            return $this->rows_affected = 1;
        }

        if (str_starts_with(ltrim($template), 'DELETE FROM')) {
            [$now, $cutoff] = $args;
            $before = count($rows);
            $rows = array_filter($rows, fn (array $r): bool => ! (($r['unlock_at'] === null || $r['unlock_at'] < $now) && $r['updated_at'] < $cutoff));

            return $this->rows_affected = $before - count($rows);
        }

        if (str_starts_with(ltrim($template), 'TRUNCATE TABLE')) {
            $rows = [];

            return 1;
        }

        throw new \LogicException('GatekeeperWpdb does not emulate: ' . $template);
    }

    public function get_var(?string $query = null, int $x = 0, int $y = 0): mixed
    {
        [$template, $args] = $this->prepared[(string) $query] ?? [(string) $query, []];

        if (str_starts_with($template, 'SHOW TABLES LIKE')) {
            $name = stripslashes((string) $args[0]);

            return isset($this->lockoutTables[$name]) ? $name : null;
        }

        $table = $this->tableIn($template);
        if ($table === null) {
            return $this->inner->get_var($query, $x, $y);
        }

        $row = $this->lockoutTables[$table][$args[0] ?? ''] ?? null;
        if ($row === null) {
            return null;
        }
        if (str_contains($template, 'SELECT attempts')) {
            return $row['attempts'];
        }
        if (str_contains($template, 'SELECT unlock_at')) {
            return $row['unlock_at'];
        }

        throw new \LogicException('GatekeeperWpdb does not emulate: ' . $template);
    }

    public function get_row(?string $query = null, string $output = 'ARRAY_A', int $y = 0): mixed
    {
        [$template, $args] = $this->prepared[(string) $query] ?? [(string) $query, []];
        $table = $this->tableIn($template);
        if ($table === null) {
            return $this->inner->get_row($query, $output, $y);
        }

        [$ip, $now] = $args;
        $row = $this->lockoutTables[$table][$ip] ?? null;
        if ($row === null || $row['unlock_at'] === null || $row['unlock_at'] <= $now) {
            return null;
        }

        return ['ip' => $row['ip'], 'unlock_at' => $row['unlock_at']];
    }

    public function get_results(?string $query = null, string $output = 'ARRAY_A'): array
    {
        [$template, $args] = $this->prepared[(string) $query] ?? [(string) $query, []];
        $table = $this->tableIn($template);
        if ($table === null) {
            return $this->inner->get_results($query, $output);
        }

        [$now] = $args;

        return array_values(array_map(
            fn (array $r): array => ['ip' => $r['ip'], 'unlock_at' => $r['unlock_at']],
            array_filter($this->lockoutTables[$table], fn (array $r): bool => $r['unlock_at'] !== null && $r['unlock_at'] > $now)
        ));
    }

    public function get_col(?string $query = null, int $x = 0): array
    {
        $table = $this->tableIn((string) $query);
        if ($table === null) {
            return $this->inner->get_col($query, $x);
        }

        return array_keys($this->lockoutTables[$table]);
    }

    public function delete(string $table, array $where, array|string $where_format = []): int|false
    {
        if (! isset($this->lockoutTables[$table])) {
            return $this->inner->delete($table, $where, $where_format);
        }
        $ip = (string) ($where['ip'] ?? '');
        $existed = isset($this->lockoutTables[$table][$ip]);
        unset($this->lockoutTables[$table][$ip]);

        return $existed ? 1 : 0;
    }

    public function get_charset_collate(): string
    {
        return '';
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        return true;
    }

    /** Name of the lockout table a statement targets, or null. */
    private function tableIn(string $sql): ?string
    {
        if (preg_match('/`([a-z0-9_]*clockwork_lockouts)`/i', $sql, $m)) {
            $this->lockoutTables[$m[1]] ??= [];

            return $m[1];
        }

        return null;
    }
}
