<?php
namespace AIWorkforce;

use AIWorkforce\Persistence\IdentityRepository;

/** Password verification and permission checks; HTTP/session handling stays in CI middleware. */
class Identity
{
    public function __construct(private IdentityRepository $users) {}
    /**
     * Authenticate using any single identifier — email address, username or
     * six-digit User ID — plus the account password.
     */
    public function authenticate(string $identifier, string $password): ?array
    {
        $user = $this->users->findUserByIdentifier(trim($identifier));
        if (!$user || !password_verify($password, $user['password_hash'])) {
            if ($user) $this->users->recordAuthEvent((int) $user['id'], 'LOGIN_FAILED');
            return null;
        }
        if (empty($user['active'])) {
            $this->users->recordAuthEvent((int) $user['id'], 'LOGIN_BLOCKED_SUSPENDED');
            return null;
        }
        $user['permissions'] = $this->users->permissionsForUser((int) $user['id']);
        $this->users->recordAuthEvent((int) $user['id'], 'LOGIN_SUCCEEDED');
        $this->users->updateUser((int) $user['id'], ['last_login_at' => gmdate('c')]);
        $user['last_login_at'] = gmdate('c');
        return IdentitySchema::stripSecrets($user);
    }
    /**
     * Rehydrate a session identity for a signed remember-me cookie. The
     * caller must verify the cookie signature first; this only rebuilds the
     * same identity shape authenticate() returns (fresh permissions, no
     * password hash) and refuses inactive accounts.
     */
    public function rememberUser(int $userId): ?array
    {
        $user = $this->users->findUserById($userId);
        if (!$user || empty($user['active'])) return null;
        $user['permissions'] = $this->users->permissionsForUser($userId);
        $this->users->recordAuthEvent($userId, 'REMEMBER_RESTORED');
        return IdentitySchema::stripSecrets($user);
    }

    public function can(array $user, string $permission): bool
    {
        if ($permission === 'system.authenticated') return !empty($user['id']);
        return in_array($permission, $user['permissions'] ?? [], true) || in_array('system.super_admin', $user['permissions'] ?? [], true);
    }

    /**
     * True only when the identifier exists, the password is correct, and the
     * account is suspended. Used to show the public "unavailable" message
     * without revealing that an account exists on a wrong password.
     */
    public function isSuspendedWithPassword(string $identifier, string $password): bool
    {
        $user = $this->users->findUserByIdentifier(trim($identifier));
        if (!$user || !empty($user['active'])) return false;
        return password_verify($password, $user['password_hash']);
    }

    public function canAccessAdmin(array $user): bool
    {
        return $this->can($user, 'admin.access') || $this->can($user, 'system.super_admin');
    }

    /**
     * One-time first-run setup: create the platform's FIRST super administrator
     * when no administrator exists yet (the cPanel deployment has no terminal,
     * so this is the no-CLI way to recover a deployment whose import lost the
     * seeded admin). Fail-closed: throws RuntimeException when an administrator
     * already exists or the database cannot be queried.
     *
     * @return array the created identity (secrets stripped, permissions loaded)
     */
    public function createFirstSuperAdmin(string $email, string $password, string $displayName): array
    {
        $exists = $this->users->superAdminExists();
        if ($exists === null) throw new \RuntimeException('ADMIN_SETUP_DATABASE_UNAVAILABLE');
        if ($exists === true) throw new \RuntimeException('ADMIN_ALREADY_EXISTS');
        $now = gmdate('c');
        $user = $this->users->createUser([
            'email' => strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => $displayName,
            'active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'last_login_at' => null,
        ]);
        if (empty($user['id'])) throw new \RuntimeException('ADMIN_SETUP_CREATE_FAILED');
        $role = $this->users->ensureRole('super_admin', 'Super administrator');
        // Guarantee the essentials even if role grants are missing from a partial import.
        foreach (['system.super_admin' => 'Full platform administration', 'admin.access' => 'Access the administrator portal'] as $code => $name) {
            $this->users->grantRolePermission($role, $this->users->ensurePermission($code, $name));
        }
        $this->users->assignRole((int) $user['id'], $role);
        $user['permissions'] = $this->users->permissionsForUser((int) $user['id']);
        return IdentitySchema::stripSecrets($user);
    }
}
