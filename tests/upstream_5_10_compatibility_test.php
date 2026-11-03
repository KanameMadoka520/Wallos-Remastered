<?php
// Uses only an in-memory database, stubbed HTTP and private temporary sessions.
ob_start();
$checks = 0;
function upstream_check($ok, $message) {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
$httpResponses = [];
$httpCalls = [];
function frankfurter_http_get($url, $context) {
    global $httpResponses, $httpCalls;
    $httpCalls[] = [$url, stream_context_get_options($context)];
    if (!$httpResponses) throw new RuntimeException('Unexpected provider request.');
    return array_shift($httpResponses);
}
require_once __DIR__ . '/../includes/frankfurter.php';
require_once __DIR__ . '/../includes/exchange_rate_writer.php';
require_once __DIR__ . '/../includes/subscription_reference_validation.php';
require_once __DIR__ . '/../includes/webhook_helper.php';
require_once __DIR__ . '/../includes/webhook_headers.php';
require_once __DIR__ . '/../includes/instance_config.php';
require_once __DIR__ . '/../includes/default_user_seed.php';
require_once __DIR__ . '/../includes/oidc/session_persist.php';
require_once __DIR__ . '/../includes/oidc/logout_url.php';

$sessionDirectory = sys_get_temp_dir() . '/wallos-upstream-' . bin2hex(random_bytes(8));
mkdir($sessionDirectory, 0700);
$db = new SQLite3(':memory:');
$db->enableExceptions(true);
try {
    foreach (['categories', 'household', 'payment_methods'] as $table) {
        $db->exec("CREATE TABLE $table(id INTEGER PRIMARY KEY, user_id INTEGER)");
        $db->exec("INSERT INTO $table VALUES(100,1),(200,2)");
    }
    $db->exec('CREATE TABLE currencies(id INTEGER PRIMARY KEY, user_id INTEGER, code TEXT, rate REAL)');
    $db->exec("INSERT INTO currencies VALUES(100,1,'EUR',1),(101,1,'USD',2),(102,1,'CNY',8),(200,2,'EUR',9)");
    $db->exec('CREATE TABLE subscriptions(id INTEGER PRIMARY KEY, user_id INTEGER, lifecycle_status TEXT)');
    $db->exec("INSERT INTO subscriptions VALUES(100,1,'active'),(101,1,'trash'),(200,2,'active')");
    $owned = ['currency_id'=>100, 'category_id'=>100, 'payer_user_id'=>100, 'payment_method_id'=>100];
    upstream_check(wallos_subscription_invalid_reference($db, 1, $owned) === null, 'Owned references must work.');
    foreach (array_keys($owned) as $field) {
        upstream_check(wallos_subscription_invalid_reference($db, 1, array_replace($owned, [$field=>200])) === $field, 'Cross-account ' . $field . ' must fail.');
        upstream_check(wallos_subscription_invalid_reference($db, 1, array_replace($owned, [$field=>'100oops'])) === $field, 'Malformed ' . $field . ' must fail.');
    }
    foreach ([101, 200, '100oops'] as $id) {
        upstream_check(wallos_subscription_invalid_reference($db, 1, $owned + ['replacement_subscription_id'=>$id]) === 'replacement_subscription_id', 'Unavailable replacement must fail.');
    }
    upstream_check(wallos_subscription_invalid_reference($db, 1, $owned + ['id'=>100,'replacement_subscription_id'=>100]) !== null, 'Self replacement must fail.');
    upstream_check(wallos_subscription_invalid_reference($db, 1, $owned, [['currency_id'=>200]]) === 'subscription_price_rules.currency_id', 'Discount rules cannot use another account currency.');
    upstream_check(wallos_subscription_invalid_reference($db, 1, $owned, [['currency_id'=>101]]) === null, 'Owned discount currency must work.');

    foreach (['He said "hi"', '"', "trailing\\", "line\nnext", '', 'A & B'] as $value) {
        $body = '{"notes":"' . webhookJsonEscape($value) . '"}';
        upstream_check(json_decode($body, true, 512, JSON_THROW_ON_ERROR)['notes'] === $value, 'Webhook JSON must round trip quotes and backslashes.');
    }
    foreach (['null', 'true', '7', '{bad json}'] as $input) {
        upstream_check(webhook_custom_headers($input) === [], 'Malformed header settings cannot crash a notification run.');
    }
    upstream_check(webhook_custom_headers('{"Authorization":"Bearer fixture"}') === ['Authorization: Bearer fixture'], 'Object headers must work.');
    upstream_check(webhook_custom_headers('["X-Fixture: yes"]') === ['X-Fixture: yes'], 'List headers must work.');
    upstream_check(webhook_custom_headers("X-Fixture: yes\nX-Other: true") === ['X-Fixture: yes', 'X-Other: true'], 'Legacy newline headers must work.');
    upstream_check(strpos(implode('', webhook_custom_headers('{"X-Fixture":"a\\r\\nX-Injected: yes"}')), "\n") === false, 'Headers cannot inject a second line.');
    upstream_check(webhook_headers_with_content_type([], '{"a":1}') === ['Content-Type: application/json'], 'JSON payloads need JSON content type.');
    upstream_check(webhook_headers_with_content_type(['content-type: text/plain'], '{}') === ['content-type: text/plain'], 'Explicit content type must be kept.');

    $httpResponses = [['body'=>'[{"base":"EUR","quote":"USD","rate":1.25}]','headers'=>['HTTP/1.1 200 OK']]];
    $rates = frankfurter_latest_rates('EUR', 'EUR,USD');
    upstream_check($rates['rates'] === ['USD'=>1.25,'EUR'=>1.0], 'Frankfurter must normalize the base for legacy consumers.');
    upstream_check($httpCalls[0][1]['http']['follow_location'] === 0 && $httpCalls[0][1]['http']['timeout'] === 10, 'Provider request must not follow redirects or wait indefinitely.');
    $httpResponses = [
        ['body'=>'{"message":"invalid currency: ETH"}','headers'=>['HTTP/1.1 422 Unprocessable Entity']],
        ['body'=>'[{"quote":"USD","rate":1.2}]','headers'=>['HTTP/1.1 200 OK']],
    ];
    $rates = frankfurter_latest_rates('EUR', 'EUR,USD,ETH,XX!');
    upstream_check($rates['held'] === ['XX!', 'ETH'] && $rates['rates']['USD'] === 1.2, 'Unsupported currency must not prevent supported rates refreshing.');
    $httpResponses = [['body'=>'[{"quote":"USD","rate":1.25}]','headers'=>['HTTP/1.1 500 Error']]];
    upstream_check(!isset(frankfurter_latest_rates('EUR', 'EUR,USD')['rates']), 'HTTP failure cannot count as a successful refresh.');
    upstream_check(frankfurter_rates([['quote'=>'USD','rate'=>-1],['quote'=>'EUR','rate'=>'oops']]) === null, 'Nonpositive or invalid rates must be refused.');

    $db->exec('CREATE TABLE last_exchange_update(date TEXT, user_id INTEGER)');
    upstream_check(wallos_store_exchange_rates($db,1,'EUR',['EUR'=>1,'USD'=>1.3]), 'Valid rates must be stored.');
    upstream_check((float)$db->querySingle('SELECT rate FROM currencies WHERE id=101') === 1.3, 'Owned currency must change.');
    upstream_check((float)$db->querySingle('SELECT rate FROM currencies WHERE id=200') === 9.0, 'Another account rate must not change.');
    upstream_check((float)$db->querySingle('SELECT rate FROM currencies WHERE id=102') === 8.0, 'An unpriced currency must retain its previous rate.');
    upstream_check($db->querySingle('SELECT date FROM last_exchange_update WHERE user_id=1') === date('Y-m-d'), 'First successful refresh must create a freshness marker.');
    upstream_check(!wallos_store_exchange_rates($db,1,'EUR',['EUR'=>1,'USD'=>-1]), 'Bad response cannot partially overwrite rates.');
    $db->exec("CREATE TRIGGER reject_freshness BEFORE INSERT ON last_exchange_update BEGIN SELECT RAISE(ABORT,'fixture'); END");
    upstream_check(!wallos_store_exchange_rates($db,1,'EUR',['EUR'=>1,'USD'=>7]), 'Freshness failure must reject refresh.');
    upstream_check((float)$db->querySingle('SELECT rate FROM currencies WHERE id=101') === 1.3, 'Failed marker write must roll back rate updates.');
    upstream_check((int)$db->querySingle('SELECT COUNT(*) FROM last_exchange_update') === 1, 'Failed marker replacement must retain the previous marker.');

    $db->exec("CREATE TABLE admin(id INTEGER, smtp_address TEXT, smtp_port TEXT, encryption TEXT, smtp_username TEXT, smtp_password TEXT, from_email TEXT, server_url TEXT)");
    $db->exec("INSERT INTO admin VALUES(1,'smtp.example.invalid','587','tls','fixture','stored-fixture','fixture@example.invalid','https://example.invalid')");
    foreach (array_values(wallos_instance_config_variables()) as $variable) { putenv($variable); putenv($variable.'_FILE'); }
    $stored = $db->querySingle('SELECT * FROM admin', true);
    upstream_check(wallos_get_admin_settings($db) === $stored, 'No environment overrides must preserve stored settings.');
    $secretFile = $sessionDirectory.'/smtp-secret';file_put_contents($secretFile,"fixture-managed-secret\n");chmod($secretFile,0600);
    putenv('WALLOS_SMTP_PASSWORD=stale-fixture');putenv('WALLOS_SMTP_PASSWORD_FILE='.$secretFile);putenv('WALLOS_SERVER_URL=https://managed.example.invalid');
    $configuration = wallos_get_effective_admin_configuration($db);
    upstream_check($configuration['settings']['smtp_password'] === 'fixture-managed-secret', 'Mounted secret must take precedence over environment value.');
    upstream_check($configuration['settings']['server_url'] === 'https://managed.example.invalid', 'Managed URL must apply at runtime.');
    upstream_check($db->querySingle('SELECT smtp_password FROM admin') === 'stored-fixture', 'Reading managed settings must not persist secrets.');
    putenv('WALLOS_SMTP_PORT=70000');
    upstream_check(wallos_get_admin_settings($db)['smtp_port'] === '587', 'Invalid managed port must retain stored port and report a note.');
    putenv('WALLOS_SMTP_PORT');

    $de = wallos_get_default_categories('de');
    upstream_check($de[1]['name'] === 'Unterhaltung' && $de[0]['id'] === 1, 'New German accounts get translated names with stable IDs.');
    upstream_check(wallos_get_default_categories('zh_cn')[1]['name'] === '娱乐', 'Chinese custom default names must remain stable.');
    upstream_check(wallos_get_default_payment_methods('de')[0]['name'] === 'PayPal', 'Brand names must remain stable.');
    upstream_check(wallos_get_default_categories('../../db/wallos')[1]['name'] === 'Entertainment', 'Seed language cannot select arbitrary files.');

    // Locale files are included before headers; accidental output breaks auth/JSON.
    foreach (glob(__DIR__ . '/../includes/i18n/*.php') as $localeFile) {
        if (basename($localeFile) === 'languages.php') continue;
        ob_start();
        (static function ($file) { require $file; })($localeFile);
        $localeOutput = ob_get_clean();
        upstream_check($localeOutput === '', 'Locale must not output literal PHP or whitespace: ' . basename($localeFile));
    }

    ini_set('session.save_path', $sessionDirectory);
    session_id('upstream-state-fixture');session_start();$_SESSION['oidc_state']='old-fixture-state';session_write_close();session_start();
    unset($_SESSION['oidc_state']);
    upstream_check(oidc_persist_session(), 'OIDC state consumption must be persisted before token exchange.');
    upstream_check(strpos(file_get_contents($sessionDirectory.'/sess_upstream-state-fixture'),'old-fixture-state') === false, 'Concurrent callback must not observe consumed state.');
    session_destroy();
    $url = oidc_build_logout_url('https://id.example.invalid/logout?existing=1','https://app.example.invalid/callback?a=1&b=2','fixture-token','fixture-client');
    parse_str(parse_url($url,PHP_URL_QUERY),$params);
    upstream_check($params['existing']==='1' && $params['id_token_hint']==='fixture-token' && $params['client_id']==='fixture-client', 'OIDC logout must include identity hints and retain existing query.');
    upstream_check($params['post_logout_redirect_uri']==='https://app.example.invalid/callback?a=1&b=2', 'OIDC logout return URI must be encoded exactly once.');
    ob_end_clean();
    echo "PASS: $checks upstream compatibility checks.\n";
} catch (Throwable $error) {
    ob_end_clean();
    fwrite(STDERR,'FAIL: '.$error->getMessage()."\n");exit(1);
} finally {
    $db->close();
    foreach (glob($sessionDirectory.'/*') as $file) unlink($file);
    rmdir($sessionDirectory);
}
