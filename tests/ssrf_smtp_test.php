<?php

require_once __DIR__ . '/../includes/ssrf_helper.php';

putenv('SSRF_ALLOWLIST');
unset($_ENV['SSRF_ALLOWLIST'], $_SERVER['SSRF_ALLOWLIST']);

function wallos_smtp_ssrf_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new SQLite3(':memory:');

try {
    $db->exec('CREATE TABLE admin (local_webhook_notifications_allowlist TEXT)');
    $db->exec("INSERT INTO admin (local_webhook_notifications_allowlist) VALUES ('')");

    $publicTarget = wallos_resolve_smtp_target('8.8.8.8', 587, $db);
    wallos_smtp_ssrf_assert($publicTarget !== false, 'Public SMTP IP should be accepted.');
    wallos_smtp_ssrf_assert($publicTarget['connect_host'] === '8.8.8.8', 'SMTP connection must be pinned to the validated IP.');

    $mail = new stdClass();
    $mail->SMTPOptions = [];
    wallos_configure_smtp_target($mail, $publicTarget);
    wallos_smtp_ssrf_assert($mail->Host === '8.8.8.8', 'PHPMailer must connect to the pinned IP.');
    wallos_smtp_ssrf_assert($mail->SMTPOptions['ssl']['peer_name'] === '8.8.8.8', 'TLS peer name must remain verified.');
    wallos_smtp_ssrf_assert(!validate_smtp_host('127.0.0.1', 25, $db), 'Loopback SMTP IP must be rejected.');
    wallos_smtp_ssrf_assert(!validate_smtp_host('169.254.169.254', 25, $db), 'Link-local SMTP IP must be rejected.');
    wallos_smtp_ssrf_assert(!validate_smtp_host('::ffff:127.0.0.1', 25, $db), 'Mapped loopback SMTP IP must be rejected.');
    wallos_smtp_ssrf_assert(!validate_smtp_host("8.8.8.8\nlocalhost", 25, $db), 'Control characters must be rejected.');
    wallos_smtp_ssrf_assert(!validate_smtp_host('8.8.8.8', 0, $db), 'Invalid SMTP ports must be rejected.');

    $db->exec("UPDATE admin SET local_webhook_notifications_allowlist = '127.0.0.1:2525'");
    wallos_smtp_ssrf_assert(validate_smtp_host('127.0.0.1', 2525, $db), 'Exact allowlisted SMTP host and port should be accepted.');
    wallos_smtp_ssrf_assert(!validate_smtp_host('127.0.0.1', 25, $db), 'Allowlisted host and port must not open other ports.');

    echo "SMTP SSRF validation test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $db->close();
}

?>
