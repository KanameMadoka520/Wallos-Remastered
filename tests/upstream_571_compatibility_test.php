<?php

require_once __DIR__ . '/../includes/upcoming_payments.php';
require_once __DIR__ . '/../includes/upcoming_cancellations.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/ssrf_helper.php';
require_once __DIR__ . '/../includes/ical_helper.php';

function compatibility_assert($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

try {
    $db = new SQLite3(':memory:');
    $db->enableExceptions(true);
    $db->exec('CREATE TABLE admin (id INTEGER PRIMARY KEY, local_webhook_notifications_allowlist TEXT)');
    $db->exec("INSERT INTO admin VALUES (1, '127.0.0.1')");
    $db->exec('CREATE TABLE settings (user_id INTEGER, screenshot_privacy_mode INTEGER DEFAULT 0)');
    $db->exec('INSERT INTO settings VALUES (1, 1)');
    $db->exec('CREATE TABLE subscriptions (id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, logo TEXT,
        logo_text_color TEXT, logo_variant TEXT, price REAL, currency_id INTEGER, cycle INTEGER,
        frequency INTEGER, cancellation_date TEXT, next_payment TEXT, inactive INTEGER,
        lifecycle_status TEXT, notes TEXT)');
    $stmt = $db->prepare("INSERT INTO subscriptions VALUES (:id, :user, 'test', '', NULL, NULL, 10, 1,
        :cycle, 1, '2099-01-01', '2099-01-01', 0, :status, 'A &amp; B')");
    foreach ([[1, 1, 3, 'active'], [2, 1, 3, 'trashed'], [3, 2, 3, 'active'], [4, 1, 5, 'active']] as [$id, $user, $cycle, $status]) {
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->bindValue(':user', $user, SQLITE3_INTEGER);
        $stmt->bindValue(':cycle', $cycle, SQLITE3_INTEGER);
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $stmt->execute();
    }
    $before = $db->querySingle('SELECT notes FROM subscriptions WHERE id = 1');
    require __DIR__ . '/../migrations/000082.php';
    $db->exec('UPDATE settings SET upcoming_payments_limit = 20');
    require __DIR__ . '/../migrations/000082.php';
    compatibility_assert((int)$db->querySingle('SELECT upcoming_payments_limit FROM settings') === 20, 'Repeated migration lost valid preference');
    compatibility_assert((int)$db->querySingle('SELECT screenshot_privacy_mode FROM settings') === 1, 'Migration changed privacy preference');
    compatibility_assert($db->querySingle('SELECT notes FROM subscriptions WHERE id = 1') === $before, 'Migration changed stored notes');
    compatibility_assert(array_column(get_upcoming_payments($db, 1, 20), 'id') === [1], 'Upcoming payments leaked other user, trash or one-time purchase');
    compatibility_assert(array_column(get_upcoming_cancellations($db, 1), 'id') === [1], 'Cancellation list leaked other user, trash or one-time purchase');
    compatibility_assert(parse_upcoming_payments_limit(4) === null && parse_upcoming_payments_limit('20') === 20, 'Invalid limit validation');
    compatibility_assert(is_url_safe_for_ssrf('http://127.0.0.1', $db, 2) === false, 'Standard user allowlist must default off');
    $db->exec('UPDATE admin SET allow_standard_users_local_webhooks = 1');
    compatibility_assert(is_array(is_url_safe_for_ssrf('http://127.0.0.1', $db, 2)), 'Explicit allowlist opt-in did not work');
    compatibility_assert(is_url_safe_for_ssrf('http://127.0.0.2', $db, 2) === false, 'Opt-in bypassed host allowlist');
    $html = render_notes_markdown("**Bold**\n<script>alert(1)</script>\n[bad](javascript:alert(1))");
    $longLine = 'DESCRIPTION:' . str_repeat('中文备注😀', 40);
    $folded = icalFold($longLine);
    compatibility_assert(str_replace("\r\n ", '', $folded) === $longLine, 'Calendar folding changed UTF-8 text');
    foreach (explode("\r\n", $folded) as $line) {
        compatibility_assert(strlen($line) <= 75 && preg_match('//u', $line) === 1, 'Calendar line exceeds 75 octets or splits UTF-8');
    }
    compatibility_assert(str_contains($html, '<strong>Bold</strong>'), 'Markdown formatting missing');
    compatibility_assert(!str_contains($html, '<script>') && !str_contains($html, 'href="javascript:'), 'Markdown allowed active content');
    echo "Upstream 5.7.1 migration, privacy preservation, lifecycle, allowlist and Markdown compatibility passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . "\n");
    exit(1);
}
