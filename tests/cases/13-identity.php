<?php
use Aegis\Identity;
use Aegis\Persistence\IdentityRepository;

function fx_identity(): array {
    $repo = new class implements IdentityRepository {
        public array $events = [];
        public array $user;
        public function __construct() { $this->user = ['id' => 1, 'email' => 'admin@example.test', 'password_hash' => password_hash('safe-password', PASSWORD_DEFAULT), 'active' => 1]; }
        public function findUserByEmail(string $email): ?array { return $email === $this->user['email'] ? $this->user : null; }
        public function findUserById(int $id): ?array { return $id === 1 ? $this->user : null; }
        public function createUser(array $user): array { return $user; }
        public function permissionsForUser(int $userId): array { return ['sports.manage']; }
        public function recordAuthEvent(int $userId, string $type, array $detail = []): void { $this->events[] = $type; }
    };
    return [new Identity($repo), $repo];
}
test('identity authenticates a password hash and removes secret hash from response', function () {
    [$identity, $repo] = fx_identity(); $user = $identity->authenticate('ADMIN@example.test', 'safe-password');
    assert_equals('LOGIN_SUCCEEDED', $repo->events[0]); assert_false(isset($user['password_hash'])); assert_true($identity->can($user, 'sports.manage'));
});
test('identity rejects invalid password and records failure', function () {
    [$identity, $repo] = fx_identity(); assert_equals(null, $identity->authenticate('admin@example.test', 'wrong'));
    assert_equals('LOGIN_FAILED', $repo->events[0]);
});
