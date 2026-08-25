<?php
namespace AIWorkforce;

/**
 * Ensures the account-profile columns exist on every request.
 *
 * production.sql historically created `users` without username / user_uid /
 * profile_image. cPanel imports that file and never runs the installer, so
 * UPDATE users SET username=… fatals (HTTP 500) and avatar paths cannot be
 * stored. This upgrade is idempotent and must not break existing rows.
 */
final class IdentitySchema
{
    private static bool $done = false;

    public static function ensure(object $db): void
    {
        if (self::$done) return;
        self::$done = true;
        try {
            if (!$db->table_exists('users')) return;
        } catch (\Throwable $e) {
            return;
        }

        $fields = self::fields($db);
        $sqlite = self::isSqlite($db);
        $alters = [
            'username' => $sqlite ? 'TEXT' : 'VARCHAR(64) NULL',
            'user_uid' => $sqlite ? 'TEXT' : 'CHAR(6) NULL',
            'profile_image' => $sqlite ? 'TEXT' : 'VARCHAR(255) NULL',
        ];
        foreach ($alters as $col => $def) {
            if (in_array($col, $fields, true)) continue;
            try { $db->query("ALTER TABLE users ADD COLUMN {$col} {$def}"); }
            catch (\Throwable $e) { /* column already exists on a racing request */ }
        }

        self::backfill($db);
        self::ensureUniqueIndexes($db, $sqlite);
    }

    /** @return array<int,string> */
    public static function fields(object $db): array
    {
        try {
            $list = $db->list_fields('users');
            return is_array($list) ? array_values($list) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function has(object $db, string $column): bool
    {
        return in_array($column, self::fields($db), true);
    }

    private static function isSqlite(object $db): bool
    {
        $driver = (string) ($db->dbdriver ?? '');
        $sub = (string) ($db->subdriver ?? '');
        return str_contains($driver, 'sqlite') || $sub === 'sqlite';
    }

    private static function backfill(object $db): void
    {
        try { $rows = $db->select('id, email, display_name, username, user_uid')->get('users')->result_array(); }
        catch (\Throwable $e) { return; }
        if (!is_array($rows)) return;

        $takenUid = [];
        $takenName = [];
        foreach ($rows as $r) {
            if (!empty($r['user_uid'])) $takenUid[(string) $r['user_uid']] = true;
            if (!empty($r['username'])) $takenName[strtolower((string) $r['username'])] = true;
        }
        foreach ($rows as $r) {
            $patch = [];
            if (empty($r['user_uid'])) {
                do { $uid = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT); }
                while (isset($takenUid[$uid]));
                $takenUid[$uid] = true;
                $patch['user_uid'] = $uid;
            }
            if (empty($r['username'])) {
                $base = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '_', (string) ($r['display_name'] ?: $r['email']))) ?? '');
                $base = substr($base, 0, 16);
                if ($base === '' || !preg_match('/^[a-z]/', $base)) $base = 'u' . $base;
                $base = str_pad($base, 3, '_');
                $candidate = substr($base, 0, 18);
                $n = 1;
                while (isset($takenName[$candidate])) {
                    $candidate = substr($base, 0, max(2, 18 - strlen((string) $n))) . $n;
                    $n++;
                }
                $takenName[$candidate] = true;
                $patch['username'] = $candidate;
            }
            if (!$patch) continue;
            try { $db->where('id', (int) $r['id'])->update('users', $patch); }
            catch (\Throwable $e) { /* never break a page load for backfill */ }
        }
    }

    private static function ensureUniqueIndexes(object $db, bool $sqlite): void
    {
        $stmts = $sqlite
            ? [
                'CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users(username)',
                'CREATE UNIQUE INDEX IF NOT EXISTS idx_users_user_uid ON users(user_uid)',
            ]
            : [
                'CREATE UNIQUE INDEX uq_users_username ON users(username)',
                'CREATE UNIQUE INDEX uq_users_user_uid ON users(user_uid)',
            ];
        foreach ($stmts as $sql) {
            try { $db->query($sql); }
            catch (\Throwable $e) { /* already exists */ }
        }
    }
}
