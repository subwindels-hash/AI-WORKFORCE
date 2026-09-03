<?php
/**
 * DIRECT MESSAGES — member ⇄ administrator support threads and admin-sent
 * notifications: input normalization, thread read-state per side, console
 * thread collapse, and delivery to a single member or every active account.
 */
use AIWorkforce\Messaging\DirectMessages;

test('direct messages: body validation and preview helpers', function () {
    assert_true(DirectMessages::validBody(DirectMessages::cleanBody('  hello support  ')), 'trimmed body is valid');
    assert_equals('hello support', DirectMessages::cleanBody('  hello support  '), 'cleanBody trims');
    assert_false(DirectMessages::validBody(DirectMessages::cleanBody('   ')), 'blank body rejected');
    $tooLong = str_repeat('a', DirectMessages::MAX_BODY + 50);
    assert_true(mb_strlen(DirectMessages::cleanBody($tooLong)) === DirectMessages::MAX_BODY, 'body capped at MAX_BODY');
    assert_true(DirectMessages::validBody(DirectMessages::cleanBody($tooLong)), 'capped body stays valid');
    assert_equals('one two', DirectMessages::preview("one\ntwo"), 'preview collapses whitespace');
    assert_equals(10, mb_strlen(DirectMessages::preview('abcdefghijklmnopqrst', 10)), 'preview truncates with ellipsis');
});

test('direct messages: repository append/thread/read-state round trip', function () {
    $repo = platform()->model->messages;
    $userId = 987654;
    $repo->deleteForUser($userId);

    $repo->append(['user_id' => $userId, 'sender_id' => $userId, 'sender_role' => 'user', 'sender_label' => 'member', 'body' => 'hello support']);
    $repo->append(['user_id' => $userId, 'sender_id' => 1, 'sender_role' => 'admin', 'sender_label' => 'Support Admin', 'body' => 'hi member, how can we help?']);

    $thread = $repo->threadFor($userId);
    assert_equals(2, count($thread), 'both messages in thread');
    assert_equals('hello support', $thread[0]['body'], 'oldest first');
    assert_equals(0, (int) $thread[0]['read_by_admin'], 'member message starts admin-unread');
    assert_equals(1, (int) $thread[0]['read_by_user'], 'sender side is already read');
    assert_equals(0, (int) $thread[1]['read_by_user'], 'admin message starts member-unread');
    assert_equals(1, (int) $thread[1]['read_by_admin'], 'admin side is already read');

    // Independent badges per side.
    assert_equals(1, $repo->unreadForUser($userId), 'member has one unread admin reply');
    $counts = $repo->counts();
    assert_true($counts['threads'] >= 1, 'at least one console thread');
    assert_true($counts['unreadThreads'] >= 1, 'thread flagged for admin reply');
    assert_true($counts['unreadMessages'] >= 1, 'unread member message counted');

    assert_equals(1, $repo->markReadByAdmin($userId), 'admin opening the thread reads the member message');
    assert_equals(1, $repo->unreadForUser($userId), 'member unread unaffected by admin read');
    assert_equals(1, $repo->markReadByUser($userId), 'member opening the thread reads the admin message');
    assert_equals(0, $repo->unreadForUser($userId), 'member badge cleared');
    assert_equals(0, $repo->markReadByUser($userId), 're-reading is a no-op');

    $repo->deleteForUser($userId);
    assert_equals([], $repo->threadFor($userId), 'thread removed');
});

test('direct messages: collapse groups newest-first per member with unread counts', function () {
    $rows = [
        ['user_id' => 2, 'username' => 'beta', 'email' => 'b@x.io', 'display_name' => 'Beta', 'user_uid' => '100002', 'active' => 1, 'sender_role' => 'admin', 'sender_label' => 'Support', 'body' => 'latest beta', 'read_by_admin' => 1, 'read_by_user' => 0, 'created_at' => '2026-01-03T10:00:00+00:00'],
        ['user_id' => 1, 'username' => 'alpha', 'email' => 'a@x.io', 'display_name' => 'Alpha', 'user_uid' => '100001', 'active' => 1, 'sender_role' => 'user', 'sender_label' => 'alpha', 'body' => 'newest alpha msg', 'read_by_admin' => 1, 'read_by_user' => 1, 'created_at' => '2026-01-02T10:00:00+00:00'],
        ['user_id' => 2, 'username' => 'beta', 'email' => 'b@x.io', 'display_name' => 'Beta', 'user_uid' => '100002', 'active' => 1, 'sender_role' => 'user', 'sender_label' => 'beta', 'body' => 'older beta question', 'read_by_admin' => 0, 'read_by_user' => 1, 'created_at' => '2026-01-01T10:00:00+00:00'],
    ];
    $threads = DirectMessages::collapse($rows);
    assert_equals(2, count($threads), 'one row per member');
    assert_equals(2, (int) $threads[0]['user_id'], 'most recent activity first');
    assert_equals('latest beta', $threads[0]['last_body'], 'last message preview is the newest');
    assert_equals(1, $threads[0]['unread'], 'unread member messages counted');
    assert_equals(2, $threads[0]['total'], 'windowed message count kept');
    assert_equals('admin', $threads[0]['last_sender_role'], 'last sender role captured');
    assert_equals(0, $threads[1]['unread'], 'read thread has zero unread');
});

test('admin notification: targeted delivery reaches exactly one member inbox', function () {
    $p = platform();
    $now = gmdate('c');
    $member = $p->model->identity->createUser([
        'email' => 'dm-' . uniqid() . '@example.com',
        'password_hash' => password_hash('long-password-123456', PASSWORD_DEFAULT),
        'display_name' => 'Notify Target', 'active' => 1,
        'created_at' => $now, 'updated_at' => $now, 'last_login_at' => null,
    ]);
    $other = $p->model->identity->createUser([
        'email' => 'dm-' . uniqid() . '@example.com',
        'password_hash' => password_hash('long-password-123456', PASSWORD_DEFAULT),
        'display_name' => 'Notify Bystander', 'active' => 1,
        'created_at' => $now, 'updated_at' => $now, 'last_login_at' => null,
    ]);
    $uid = 'n-' . uniqid();
    $p->notifications->notify('admin_message', 'warning', 'Maintenance ' . $uid, [
        'message' => 'Short maintenance window', 'from' => 'Admin',
    ], null, (int) $member['id']);

    $inbox = $p->notifications->inbox((int) $member['id'], false, 30);
    $hit = array_values(array_filter($inbox['notifications'], fn($n) => str_contains((string) $n['title'], $uid)));
    assert_equals(1, count($hit), 'notification in the member inbox');
    assert_equals('admin_message', $hit[0]['type']);
    assert_equals('warning', $hit[0]['severity']);
    assert_equals('Short maintenance window', $hit[0]['detail']['message'] ?? null, 'message payload preserved');
    assert_equals((int) $member['id'], (int) $hit[0]['user_id'], 'targeted — not a broadcast row');

    $bystander = $p->notifications->inbox((int) $other['id'], false, 30);
    $miss = array_values(array_filter($bystander['notifications'], fn($n) => str_contains((string) $n['title'], $uid)));
    assert_equals(0, count($miss), 'other members do not see the targeted notification');
});

test('admin notification: broadcast helper reaches every active recipient', function () {
    $p = platform();
    $now = gmdate('c');
    $recipients = [];
    for ($i = 0; $i < 2; $i++) {
        $recipients[] = $p->model->identity->createUser([
            'email' => 'bc-' . uniqid() . '@example.com',
            'password_hash' => password_hash('long-password-123456', PASSWORD_DEFAULT),
            'display_name' => 'Broadcast ' . $i, 'active' => 1,
            'created_at' => $now, 'updated_at' => $now, 'last_login_at' => null,
        ]);
    }
    // Same per-recipient loop the admin console uses for "all users".
    $uid = 'b-' . uniqid();
    $delivered = 0;
    foreach ($p->model->identity->activeRecipients() as $recipient) {
        if (!in_array((int) $recipient['id'], array_map(fn($r) => (int) $r['id'], $recipients), true)) continue;
        $result = $p->notifications->notify('admin_broadcast', 'info', 'Announcement ' . $uid, [
            'message' => 'Hello everyone', 'from' => 'Admin',
        ], null, (int) $recipient['id']);
        if (!empty($result['created'])) $delivered++;
    }
    assert_equals(2, $delivered, 'one delivered copy per recipient');
    foreach ($recipients as $recipient) {
        $inbox = $p->notifications->inbox((int) $recipient['id'], false, 30);
        $hit = array_values(array_filter($inbox['notifications'], fn($n) => str_contains((string) $n['title'], $uid)));
        assert_equals(1, count($hit), 'recipient has their own copy');
        assert_equals('admin_broadcast', $hit[0]['type']);
    }
    // Read state is per member: one acknowledgement never silences the other.
    $first = $p->notifications->inbox((int) $recipients[0]['id'], true, 30);
    $firstHit = array_values(array_filter($first['notifications'], fn($n) => str_contains((string) $n['title'], $uid)));
    assert_equals(1, count($firstHit));
    $p->notifications->markRead((string) $firstHit[0]['id'], (int) $recipients[0]['id']);
    $second = $p->notifications->inbox((int) $recipients[1]['id'], true, 30);
    $secondHit = array_values(array_filter($second['notifications'], fn($n) => str_contains((string) $n['title'], $uid)));
    assert_equals(1, count($secondHit), 'second member still sees their copy as unread');
});
