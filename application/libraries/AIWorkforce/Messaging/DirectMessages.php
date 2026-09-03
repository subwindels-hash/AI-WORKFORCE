<?php
namespace AIWorkforce\Messaging;

/**
 * Direct member ⇄ administrator messages.
 *
 * SQL stays in AIWorkforce_model; this class only validates input and shapes
 * rows for the thread UIs (member "Messages" page and the admin console).
 * A thread is keyed by the member's user id — every message stores the side
 * it came from, and each side keeps its own read flag so the member's badge
 * and the admin console badge move independently.
 */
final class DirectMessages
{
    public const MAX_BODY = 5000;

    /** Normalize a posted body: trim, collapse NULs, cap length. */
    public static function cleanBody(string $body): string
    {
        $body = str_replace("\0", '', $body);
        $body = trim($body);
        return mb_substr($body, 0, self::MAX_BODY);
    }

    public static function validBody(string $body): bool
    {
        return $body !== '' && mb_strlen($body) <= self::MAX_BODY;
    }

    /** Short single-line preview for thread lists. */
    public static function preview(string $body, int $length = 90): string
    {
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);
        return mb_strlen($body) <= $length ? $body : mb_substr($body, 0, $length - 1) . '…';
    }

    /**
     * Collapse a newest-first message stream (already joined with users) into
     * one thread row per member: newest message first, thread order = the
     * most recent activity across the fetched window.
     *
     * @param list<array<string,mixed>> $rows DESC-ordered direct_messages rows
     * @return list<array<string,mixed>>
     */
    public static function collapse(array $rows): array
    {
        $threads = [];
        foreach ($rows as $r) {
            $userId = (int) ($r['user_id'] ?? 0);
            if ($userId <= 0) continue;
            if (!isset($threads[$userId])) {
                $threads[$userId] = [
                    'user_id' => $userId,
                    'username' => $r['username'] ?? null,
                    'display_name' => $r['display_name'] ?? null,
                    'email' => $r['email'] ?? null,
                    'user_uid' => $r['user_uid'] ?? null,
                    'profile_image' => $r['profile_image'] ?? null,
                    'active' => isset($r['active']) ? (int) $r['active'] : 1,
                    'last_body' => (string) ($r['body'] ?? ''),
                    'last_sender_role' => (string) ($r['sender_role'] ?? 'user'),
                    'last_sender_label' => (string) ($r['sender_label'] ?? ''),
                    'last_at' => (string) ($r['created_at'] ?? ''),
                    'total' => 0,
                    'unread' => 0,   // member messages awaiting an admin reply-view
                ];
            }
            $threads[$userId]['total']++;
            if (($r['sender_role'] ?? '') === 'user' && empty($r['read_by_admin'])) {
                $threads[$userId]['unread']++;
            }
        }
        return array_values($threads);
    }
}
