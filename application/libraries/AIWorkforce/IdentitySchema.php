<?php
namespace AIWorkforce;

/**
 * Ensures the account-profile columns exist on every request.
 *
 * production.sql historically created `users` without username / user_uid /
 * profile_image / phone / address / recovery fields. cPanel imports that
 * file and never runs the installer, so UPDATE users SET username=… fatals
 * (HTTP 500) and avatar / contact / PIN fields cannot be stored. This
 * upgrade is idempotent and must not break existing rows.
 */
final class IdentitySchema
{
    public const SECURITY_QUESTIONS = [
        'What city were you born in?',
        'What is your mother\'s maiden name?',
        'What was the name of your first school?',
        'What is the name of your first pet?',
        'What is your favorite teacher\'s name?',
    ];

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
            'phone' => $sqlite ? 'TEXT' : 'VARCHAR(40) NULL',
            'address' => $sqlite ? 'TEXT' : 'VARCHAR(255) NULL',
            'security_pin' => $sqlite ? 'TEXT' : 'CHAR(4) NULL',
            'security_question' => $sqlite ? 'TEXT' : 'VARCHAR(255) NULL',
            'security_answer' => $sqlite ? 'TEXT' : 'VARCHAR(255) NULL',
        ];
        $added = false;
        foreach ($alters as $col => $def) {
            if (in_array($col, $fields, true)) continue;
            try { $db->query("ALTER TABLE users ADD COLUMN {$col} {$def}"); $added = true; }
            catch (\Throwable $e) { /* column already exists on a racing request */ }
        }
        if ($added && isset($db->data_cache) && is_array($db->data_cache)) {
            unset($db->data_cache['field_names']['users'], $db->data_cache['field_data']['users']);
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

    /** Collapse whitespace and cap at the users.phone column width. */
    public static function normalizePhone(string $phone): string
    {
        $phone = trim(preg_replace('/\s+/', ' ', $phone) ?? $phone);
        return function_exists('mb_substr') ? mb_substr($phone, 0, 40) : substr($phone, 0, 40);
    }

    /** International numbers: 7–15 digits, optional +, spaces, dashes, parentheses. */
    public static function validPhone(string $phone): bool
    {
        $phone = self::normalizePhone($phone);
        if ($phone === '') return false;
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) < 7 || strlen($digits) > 15) return false;
        return (bool) preg_match('/^[+0-9][0-9+\-() .]{6,39}$/', $phone);
    }

    /** Collapse runs of spaces; keep newlines as a single space for storage. */
    public static function normalizeAddress(string $address): string
    {
        $address = trim(preg_replace('/\s+/', ' ', str_replace(["\r\n", "\r", "\n"], ' ', $address)) ?? $address);
        return function_exists('mb_substr') ? mb_substr($address, 0, 255) : substr($address, 0, 255);
    }

    public static function validAddress(string $address): bool
    {
        $address = self::normalizeAddress($address);
        $len = function_exists('mb_strlen') ? mb_strlen($address) : strlen($address);
        return $len >= 5 && $len <= 255;
    }

    /** Exactly four digits. Spaces and punctuation are stripped first. */
    public static function normalizePin(string $pin): string
    {
        return preg_replace('/\D/', '', $pin) ?? '';
    }

    public static function validPin(string $pin): bool
    {
        return (bool) preg_match('/^\d{4}$/', self::normalizePin($pin));
    }

    /** Random 4-digit PIN assigned at signup (0000–9999, zero-padded). */
    public static function generatePin(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    public static function normalizeQuestion(string $question): string
    {
        $question = trim(preg_replace('/\s+/', ' ', $question) ?? $question);
        return function_exists('mb_substr') ? mb_substr($question, 0, 255) : substr($question, 0, 255);
    }

    public static function validQuestion(string $question): bool
    {
        $question = self::normalizeQuestion($question);
        $len = function_exists('mb_strlen') ? mb_strlen($question) : strlen($question);
        return $len >= 8 && $len <= 255;
    }

    public static function normalizeAnswer(string $answer): string
    {
        $answer = trim(preg_replace('/\s+/', ' ', $answer) ?? $answer);
        return function_exists('mb_substr') ? mb_substr($answer, 0, 120) : substr($answer, 0, 120);
    }

    public static function validAnswer(string $answer): bool
    {
        $answer = self::normalizeAnswer($answer);
        $len = function_exists('mb_strlen') ? mb_strlen($answer) : strlen($answer);
        return $len >= 2 && $len <= 120;
    }

    /**
     * Security question + answer from signup / account (PIN is assigned separately).
     *
     * @return array{security_question:string,security_answer:string}|null
     */
    public static function fromPostedQuestion(string $question, string $customQuestion, string $answer): ?array
    {
        if ($question === '__custom__') $question = $customQuestion;
        $row = [
            'security_question' => self::normalizeQuestion($question),
            'security_answer' => self::normalizeAnswer($answer),
        ];
        if (!self::validQuestion($row['security_question']) || !self::validAnswer($row['security_answer'])) {
            return null;
        }
        return $row;
    }

    /**
     * Build a persisted recovery row from account form fields.
     * Returns null when any field is missing or invalid.
     *
     * @return array{security_pin:string,security_question:string,security_answer:string}|null
     */
    public static function fromPostedRecovery(string $pin, string $question, string $customQuestion, string $answer): ?array
    {
        $q = self::fromPostedQuestion($question, $customQuestion, $answer);
        if (!$q) return null;
        $pin = self::normalizePin($pin);
        if (!self::validPin($pin)) return null;
        return array_merge(['security_pin' => $pin], $q);
    }

    /**
     * Drop password hashes always. Recovery PIN / question / answer stay only
     * when Super Admin is reading a user profile.
     */
    public static function stripSecrets(array $user, bool $keepRecovery = false): array
    {
        unset($user['password_hash']);
        if (!$keepRecovery) {
            unset($user['security_pin'], $user['security_question'], $user['security_answer']);
        }
        return $user;
    }

    private static function isSqlite(object $db): bool
    {
        $driver = (string) ($db->dbdriver ?? '');
        $sub = (string) ($db->subdriver ?? '');
        return str_contains($driver, 'sqlite') || $sub === 'sqlite';
    }

    private static function backfill(object $db): void
    {
        $select = 'id, email, display_name, username, user_uid';
        if (self::has($db, 'security_pin')) $select .= ', security_pin';
        try { $rows = $db->select($select)->get('users')->result_array(); }
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
            if (empty($r['security_pin']) || !self::validPin((string) $r['security_pin'])) {
                $patch['security_pin'] = self::generatePin();
            }
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
