<?php
namespace AIWorkforce;

/**
 * Member-facing activity feeds must not expose administrator operations
 * (provider tests, admin logins, contact-form inbox, role changes, etc.).
 */
final class MemberAudit
{
    /** Extra event types that are operator-only even without an ADMIN_ prefix. */
    private const HIDDEN_TYPES = [
        'CONTACT_INQUIRY',
        'CONTACT_REPLY',
        'IMPERSONATION_STARTED',
        'IMPERSONATION_STOPPED',
        'ROLE_ASSIGNED',
        'ROLE_REVOKED',
        'USER_SUSPENDED',
        'USER_ACTIVATED',
        'USER_DELETED',
    ];

    public static function isAdminOnly(string $type): bool
    {
        $t = strtoupper(trim($type));
        if ($t === '') {
            return false;
        }
        if (str_starts_with($t, 'ADMIN_')) {
            return true;
        }
        return in_array($t, self::HIDDEN_TYPES, true);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function forMembers(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? '');
            if (self::isAdminOnly($type)) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }
}
