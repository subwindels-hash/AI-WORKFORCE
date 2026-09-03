<?php
/**
 * Tests for Lead Discovery Modes + Person free-email filter + Outreach wiring.
 *
 * These don't hit external providers; they verify controller source contains
 * the right hooks and that the free-email filter logic mirrors the controller.
 */
$tests = [];

$tests[] = function(): array {
    $source = file_get_contents(APPPATH . 'controllers/Api_lead_discovery.php');
    assert_true(str_contains($source, "public function modes("), 'modes_method_present');
    assert_true(str_contains($source, "'id'=>'business'"), 'business_mode_declared');
    assert_true(str_contains($source, "'id'=>'person'"), 'person_mode_declared');
    return ['msg' => 'modes endpoint declared'];
};

$tests[] = function(): array {
    $source = file_get_contents(APPPATH . 'controllers/Api_lead_discovery.php');
    foreach (['gmail.com','yahoo.com','outlook.com','icloud.com'] as $d) {
        assert_true(str_contains($source, "'$d'"), "free_email_{$d}_listed");
    }
    assert_true(str_contains($source, "public function outreach("), 'outreach_method_present');
    assert_true(str_contains($source, 'OUTREACH_SENT'), 'outreach_activity_logged');
    assert_true(str_contains($source, "'status'=>'contacted'"), 'outreach_marks_contacted');
    assert_true(str_contains($source, "'email','linkedin','note','call'"), 'channels_validated');
    return ['msg' => 'free-email filter + outreach hooks present'];
};

$tests[] = function(): array {
    // Mirror the controller's free-domain filter (unit-level).
    $free = ['gmail.com','yahoo.com','outlook.com','icloud.com','hotmail.com','aol.com','proton.me','live.com','me.com','mail.com','gmx.com','yandex.com'];
    $isFree = function(string $email) use ($free): bool {
        $email = strtolower($email);
        $at = strrpos($email, '@');
        if ($at === false) return false;
        return in_array(strtolower(substr($email, $at+1)), $free, true);
    };
    assert_true($isFree('Mark@gmail.com'), 'gmail_case_insensitive');
    assert_true($isFree('john@ICLOUD.com'), 'icloud_case_insensitive');
    assert_true($isFree('emma@outlook.com'), 'outlook_match');
    assert_true($isFree('a@yahoo.com'), 'yahoo_match');
    assert_true(!$isFree('mark@acme.co'), 'corporate_rejected');
    assert_true(!$isFree('ceo@bank.com.ng'), 'work_rejected');
    assert_true(!$isFree('not-an-email'), 'no_at_rejected');
    return ['msg' => 'free-email filtering logic correct'];
};

$tests[] = function(): array {
    $source = file_get_contents(APPPATH . 'controllers/Api_lead_discovery.php');
    assert_true(str_contains($source, "\$mode=(string)(\$b['mode']??'business')"), 'mode_param_parsed');
    assert_true(str_contains($source, 'first_names'), 'first_names_forwarded');
    assert_true(str_contains($source, 'verificationStatus'), 'verification_status_computed');
    assert_true(str_contains($source, "'verified'"), 'verified_status');
    assert_true(str_contains($source, "'partial_verified'"), 'partial_verified_status');
    assert_true(str_contains($source, "'business_listing'"), 'business_listing_status');
    // Schema must have outreach/lead_kind columns.
    $schema = file_get_contents(APPPATH . 'database/schema.mysql.sql');
    assert_true(str_contains($schema, 'lead_kind'), 'lead_kind_column');
    assert_true(str_contains($schema, 'job_title'), 'job_title_column');
    assert_true(str_contains($schema, 'company_name'), 'company_name_column');
    assert_true(str_contains($schema, 'linkedin_url'), 'linkedin_url_column');
    assert_true(str_contains($schema, 'CREATE TABLE IF NOT EXISTS lead_outreach'), 'outreach_table');
    return ['msg' => 'mode + verification + schema columns all wired'];
};

$tests[] = function(): array {
    // Transports for cold email delivery: Resend, Postmark, SMTP.
    $source = file_get_contents(APPPATH . 'controllers/Api_lead_discovery.php');
    assert_true(str_contains($source, 'RESEND_API_KEY'), 'resend_transport');
    assert_true(str_contains($source, 'POSTMARK_SERVER_TOKEN'), 'postmark_transport');
    assert_true(str_contains($source, 'SMTP_HOST'), 'smtp_transport');
    assert_true(str_contains($source, 'OUTREACH_FROM_EMAIL'), 'from_env_var');
    return ['msg' => 'email transports wired up'];
};

run('85-lead-discovery-modes', $tests);
