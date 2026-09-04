<?php

test('member audit hides admin operations from workspace feeds', function () {
    $rows = [
        ['type' => 'ADMIN_API_PROVIDER_TESTED', 'summary' => 'api provider tested'],
        ['type' => 'ADMIN_ADMIN_LOGIN', 'summary' => 'admin login'],
        ['type' => 'CONTACT_INQUIRY', 'summary' => 'Public contact form'],
        ['type' => 'TRADE_ANALYZED', 'summary' => 'BTCUSDT 1h'],
        ['type' => 'SPORTS_TICKET_RECORDED', 'summary' => 'ticket'],
    ];
    $out = AIWorkforce\MemberAudit::forMembers($rows);
    $types = array_column($out, 'type');
    assert_false(in_array('ADMIN_API_PROVIDER_TESTED', $types, true));
    assert_false(in_array('ADMIN_ADMIN_LOGIN', $types, true));
    assert_false(in_array('CONTACT_INQUIRY', $types, true));
    assert_true(in_array('TRADE_ANALYZED', $types, true));
    assert_true(in_array('SPORTS_TICKET_RECORDED', $types, true));
});
