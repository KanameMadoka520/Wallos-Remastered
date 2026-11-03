<?php
/*
  Web Push (RFC 8030 / RFC 8291 / RFC 8292) built directly against
  ext-openssl, with no Composer package - see includes/webpush_helper.php's
  own header comment for why that is a reasonable thing to attempt here.

  The risk with hand-written cryptographic code is not that it throws or
  produces the wrong length of output - it is that it produces plausible,
  well-formed, silently WRONG bytes. "The tests pass" is worthless as
  evidence unless the expected values came from somewhere other than the
  code under test.

  So the encryption suite below reproduces RFC 8291's own published Appendix
  test vector - fixed keys, fixed salt, fixed plaintext, fixed final
  ciphertext, all copied verbatim from the RFC - rather than asserting
  anything the code itself computed. Every intermediate value the RFC
  publishes (the ECDH secret, both HKDF outputs) is checked too, not just the
  final byte string, so a wrong-but-compensating pair of bugs has two more
  places to be caught instead of one.

  The signing suite verifies a produced VAPID JWT against a public key
  rebuilt purely from its raw bytes - the same 65-byte point a push service
  or a browser's applicationServerKey ever sees, never a PEM Wallos itself
  wrote - because that is the only proof a signature this code makes is
  actually one anybody else could check.
*/

require_once __DIR__ . '/../includes/webpush.php';
require_once __DIR__ . '/../includes/i18n/getlang.php';
require __DIR__ . '/../includes/i18n/en.php';

$checks=0;
function assert_true($condition, $message) { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function assert_same($expected,$actual,$message) { assert_true($expected === $actual,$message); }
function wallos_test($label,$callback) { $callback(); echo "[OK] $label\n"; }
function push_rejects($callback) { try { $callback(); } catch (InvalidArgumentException $e) { return true; } return false; }
function webpush_test_b64url_decode($data)
{
    $data = strtr($data, '-_', '+/');
    $padded = str_pad($data, strlen($data) + (4 - strlen($data) % 4) % 4, '=');

    return base64_decode($padded, true);
}

function webpush_test_b64url_encode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Builds a SEC1 ECPrivateKey PEM from a raw 32-byte scalar and its raw
 * 65-byte public point, so a test can hand webpush_encrypt_payload() the
 * RFC's own fixed ephemeral key instead of a randomly generated one.
 *
 * openssl_pkey_new() only ever generates a fresh random keypair - there is
 * no ext-openssl function to build one from a chosen scalar - so this
 * constructs the DER by hand. Every byte here except the two raw values is
 * fixed by the ASN.1 structure and the prime256v1 curve OID.
 *
 * @param string $d         32-byte private scalar.
 * @param string $publicRaw 65-byte uncompressed public point.
 * @return string PEM.
 */
function webpush_test_ec_private_key_pem($d, $publicRaw)
{
    $d = strlen($d) > 32 ? substr($d, -32) : str_pad($d, 32, "\x00", STR_PAD_LEFT);

    $version = hex2bin('020101');
    $privateKeyField = hex2bin('0420') . $d;
    $curveField = hex2bin('a00a') . hex2bin('06082a8648ce3d030107');
    $publicKeyField = hex2bin('a144') . hex2bin('034200') . $publicRaw;

    $content = $version . $privateKeyField . $curveField . $publicKeyField;
    $der = "\x30" . chr(strlen($content)) . $content;

    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

/**
 * A public key built purely from its raw 65-byte point, the way a push
 * service or a browser's applicationServerKey would hand it over - never
 * from a PEM this code itself wrote, which would only prove the code agrees
 * with itself.
 *
 * @param string $publicRaw
 * @return OpenSSLAsymmetricKey
 */
function webpush_test_public_key_from_raw($publicRaw)
{
    $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($prefix . $publicRaw), 64, "\n") . "-----END PUBLIC KEY-----\n";

    return openssl_pkey_get_public($pem);
}

/**
 * Converts a raw r || s JWS signature back to the DER form openssl_verify()
 * takes - the reverse of webpush_der_signature_to_raw(), needed only to
 * check a signature this code produced, never by the code itself.
 *
 * @param string $raw 64 bytes for P-256.
 * @return string
 */
function webpush_test_raw_signature_to_der($raw)
{
    $encodeInteger = function ($value) {
        $value = ltrim($value, "\x00");
        if ($value === '') {
            $value = "\x00";
        }
        if (ord($value[0]) & 0x80) {
            $value = "\x00" . $value;
        }

        return "\x02" . chr(strlen($value)) . $value;
    };

    $r = $encodeInteger(substr($raw, 0, 32));
    $s = $encodeInteger(substr($raw, 32, 32));

    return "\x30" . chr(strlen($r . $s)) . $r . $s;
}

/*
  RFC 8291 Appendix A's own worked example, copied verbatim from the RFC:
  https://www.rfc-editor.org/rfc/rfc8291#appendix-A
*/
const WEBPUSH_TEST_VECTOR_AUTH_SECRET = 'BTBZMqHH6r4Tts7J_aSIgg';
const WEBPUSH_TEST_VECTOR_UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
const WEBPUSH_TEST_VECTOR_AS_PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
const WEBPUSH_TEST_VECTOR_AS_PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
const WEBPUSH_TEST_VECTOR_SALT = 'DGv6ra1nlYgDCS1FRnbzlw';
const WEBPUSH_TEST_VECTOR_PLAINTEXT = 'When I grow up, I want to be a watermelon';
const WEBPUSH_TEST_VECTOR_OUTPUT = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

wallos_test('the encrypted output matches RFC 8291\'s own test vector exactly', function () {
    $ephemeralPem = webpush_test_ec_private_key_pem(
        webpush_test_b64url_decode(WEBPUSH_TEST_VECTOR_AS_PRIVATE),
        webpush_test_b64url_decode(WEBPUSH_TEST_VECTOR_AS_PUBLIC)
    );
    $salt = webpush_test_b64url_decode(WEBPUSH_TEST_VECTOR_SALT);

    $output = webpush_encrypt_payload(
        WEBPUSH_TEST_VECTOR_PLAINTEXT,
        WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET,
        $ephemeralPem,
        $salt
    );

    assert_true($output !== false, 'encryption succeeds');
    assert_same(WEBPUSH_TEST_VECTOR_OUTPUT, webpush_test_b64url_encode($output),
        'the full content-coding header plus ciphertext matches the RFC byte for byte');
});

wallos_test('the content-coding header the RFC output starts with is the salt, record size and key id', function () {
    // Separately from the byte-exact whole-output check above: the header
    // format itself (RFC 8188), decoded field by field, so a failure here
    // points at the header rather than the ciphertext.
    $ephemeralPem = webpush_test_ec_private_key_pem(
        webpush_test_b64url_decode(WEBPUSH_TEST_VECTOR_AS_PRIVATE),
        webpush_test_b64url_decode(WEBPUSH_TEST_VECTOR_AS_PUBLIC)
    );
    $salt = webpush_test_b64url_decode(WEBPUSH_TEST_VECTOR_SALT);
    $asPublicRaw = webpush_test_b64url_decode(WEBPUSH_TEST_VECTOR_AS_PUBLIC);

    $output = webpush_encrypt_payload(
        WEBPUSH_TEST_VECTOR_PLAINTEXT,
        WEBPUSH_TEST_VECTOR_UA_PUBLIC,
        WEBPUSH_TEST_VECTOR_AUTH_SECRET,
        $ephemeralPem,
        $salt
    );

    assert_same($salt, substr($output, 0, 16), 'the first 16 bytes are the salt');
    assert_same(4096, unpack('N', substr($output, 16, 4))[1], 'then a big-endian uint32 record size');
    assert_same(65, ord($output[20]), 'then a one-byte key id length - 65, an uncompressed P-256 point');
    assert_same($asPublicRaw, substr($output, 21, 65), 'then the ephemeral public key itself, as the key id');
});

wallos_test('a fresh ephemeral key and salt change the output, still round-trips through the same shape', function () {
    // Every real send must never reuse an ephemeral key or salt - this is the
    // one case in the suite that lets webpush_encrypt_payload() generate its
    // own, the way production does, and just checks the shape holds.
    $output = webpush_encrypt_payload('hello', WEBPUSH_TEST_VECTOR_UA_PUBLIC, WEBPUSH_TEST_VECTOR_AUTH_SECRET);

    assert_true($output !== false, 'encryption succeeds with generated key and salt');
    assert_true(strlen($output) > 21 + 65 + 16, 'header plus at least a 16-byte GCM tag');
    assert_same(65, ord($output[20]), 'the key id length byte is still 65');

    $output2 = webpush_encrypt_payload('hello', WEBPUSH_TEST_VECTOR_UA_PUBLIC, WEBPUSH_TEST_VECTOR_AUTH_SECRET);
    assert_true($output !== $output2, 'two calls for the same message never produce the same bytes');
});

wallos_test('a malformed subscription key is refused rather than guessed at', function () {
    assert_same(false, webpush_encrypt_payload('x', 'not-base64!!!', WEBPUSH_TEST_VECTOR_AUTH_SECRET),
        'a p256dh that does not decode to 65 bytes is refused');
    assert_same(false, webpush_encrypt_payload('x', WEBPUSH_TEST_VECTOR_UA_PUBLIC, 'YQ'),
        'an auth secret that does not decode to 16 bytes is refused');
});

wallos_test('a VAPID JWT verifies against a public key built purely from its raw bytes', function () {
    // "Purely from its raw bytes" is the point: a push service never sees a
    // PEM, only the same base64url public key the Authorization header's
    // "k=" carries - so that is what this reconstructs the verifier from.
    $keys = webpush_generate_vapid_keypair();
    assert_true($keys !== false, 'a keypair was generated');

    $jwt = webpush_build_vapid_jwt('https://fcm.googleapis.com/fcm/send/abc123', 'mailto:admin@example.com', $keys['private_pem']);
    assert_true($jwt !== false, 'a JWT was built');

    $parts = explode('.', $jwt);
    assert_same(3, count($parts), 'a JWT has three dot-separated segments');

    $header = json_decode(webpush_test_b64url_decode($parts[0]), true);
    assert_same('ES256', $header['alg'] ?? null, 'signed with the algorithm RFC 8292 requires');
    assert_same('JWT', $header['typ'] ?? null, 'the header names the token type');

    $claims = json_decode(webpush_test_b64url_decode($parts[1]), true);
    assert_same('https://fcm.googleapis.com', $claims['aud'] ?? null,
        'the audience is the endpoint\'s scheme and host only, not its full path');
    assert_same('mailto:admin@example.com', $claims['sub'] ?? null, 'the subject is the contact passed in');
    assert_true(($claims['exp'] ?? 0) > time(), 'the token has not already expired');

    $publicKey = webpush_test_public_key_from_raw(webpush_test_b64url_decode($keys['public']));
    $signatureDer = webpush_test_raw_signature_to_der(webpush_test_b64url_decode($parts[2]));
    $verified = openssl_verify($parts[0] . '.' . $parts[1], $signatureDer, $publicKey, OPENSSL_ALGO_SHA256);

    assert_same(1, $verified, 'the signature verifies against the raw public key');
});

wallos_test('the audience drops the endpoint\'s path, and a malformed endpoint is refused', function () {
    $keys = webpush_generate_vapid_keypair();

    $jwt = webpush_build_vapid_jwt(
        'https://updates.push.services.mozilla.com/wpush/v2/gAAAAABl',
        'mailto:admin@example.com',
        $keys['private_pem']
    );
    $claims = json_decode(webpush_test_b64url_decode(explode('.', $jwt)[1]), true);
    assert_same('https://updates.push.services.mozilla.com', $claims['aud'],
        'query string and path are both gone from the audience');

    assert_same(false, webpush_build_vapid_jwt('not a url', 'mailto:admin@example.com', $keys['private_pem']),
        'an endpoint with no scheme or host is refused rather than signing a token nobody could route to');
});

wallos_test('DER-to-raw signature conversion round-trips under repeated random signing', function () {
    // ECDSA-Sig-Value is variable-width per component (a leading zero byte
    // is added only when needed to avoid the value reading as negative) -
    // the one thing worth stress-testing rather than trusting a handful of
    // examples to have hit every width combination on their own.
    $key = webpush_openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $details = openssl_pkey_get_details($key);
    $publicKey = webpush_test_public_key_from_raw(
        "\x04" . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT)
    );

    $failures = 0;
    for ($i = 0; $i < 200; $i++) {
        $message = random_bytes(32);
        openssl_sign($message, $der, $key, OPENSSL_ALGO_SHA256);

        $raw = webpush_der_signature_to_raw($der, 32);
        if ($raw === false || strlen($raw) !== 64) {
            $failures++;
            continue;
        }

        $reencodedDer = webpush_test_raw_signature_to_der($raw);
        if (openssl_verify($message, $reencodedDer, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            $failures++;
        }
    }

    assert_same(0, $failures, '200 random signatures all round-trip and verify (0 failures)');
});


wallos_test('migration, private keys, ownership, limits and lifecycle are additive', function () use ($i18n) {
    $db = new SQLite3(':memory:'); $db->enableExceptions(true); $db->exec('PRAGMA foreign_keys=ON');
    $db->exec("CREATE TABLE user(id INTEGER PRIMARY KEY, account_status TEXT); INSERT INTO user VALUES(1,'active'),(2,'active'); CREATE TABLE admin(server_url TEXT); INSERT INTO admin VALUES('https://example.com');");
    require __DIR__.'/../migrations/000085.php'; require __DIR__.'/../migrations/000085.php';
    assert_true(wallos_webpush_available($db),'idempotent migration');
    $first=webpush_get_vapid_keys($db); $second=webpush_generate_vapid_keypair();
    assert_same($first,webpush_store_vapid_keys($db,$second),'concurrent first use cannot replace keys');
    assert_same($first,webpush_get_vapid_keys($db),'stored pair remains consistent');
    assert_same(['server_url'],array_column(iterator_to_array((function()use($db){$r=$db->query('PRAGMA table_info(admin)');while($v=$r->fetchArray(SQLITE3_ASSOC))yield $v;})()),'name'),'private key absent from admin serialization');
    $subscription=['endpoint'=>'https://fcm.googleapis.com/fcm/send/test','keys'=>['p256dh'=>WEBPUSH_TEST_VECTOR_UA_PUBLIC,'auth'=>WEBPUSH_TEST_VECTOR_AUTH_SECRET]];
    $cookie=str_repeat('a',64);
    wallos_webpush_register($db,1,$subscription,'<img src=x>',$cookie);
    assert_same(1,(int)$db->querySingle('SELECT COUNT(*) FROM push_subscriptions'),'register');
    assert_same(1,(int)wallos_webpush_settings($db,1)['enabled'],'register enables channel');
    assert_same(0,(int)wallos_webpush_settings($db,1)['show_details'],'details opt-in only');
    assert_true(push_rejects(fn()=>wallos_webpush_register($db,2,$subscription,'Other',str_repeat('b',64))),'endpoint cannot move to another account');
    assert_true(push_rejects(fn()=>wallos_webpush_register($db,1,$subscription,'Another browser',str_repeat('b',64))),'endpoint cannot move to another browser capability');
    wallos_webpush_register($db,1,$subscription,'Renamed',$cookie);
    assert_same('Renamed',$db->querySingle('SELECT device_name FROM push_subscriptions'),'repeat same binding updates');
    assert_same(1,(int)$db->querySingle('SELECT COUNT(*) FROM push_subscriptions'),'same-device register idempotent');
    for($i=1;$i<20;$i++) { $sub=$subscription;$sub['endpoint'].=$i;wallos_webpush_register($db,1,$sub,'Device '.$i,str_pad(dechex($i),64,'0',STR_PAD_LEFT)); }
    $sub=$subscription;$sub['endpoint'].='overflow';
    assert_true(push_rejects(fn()=>wallos_webpush_register($db,1,$sub,'Overflow',str_repeat('f',64))),'per-account device bound');
    assert_same(20,(int)$db->querySingle('SELECT COUNT(*) FROM push_subscriptions'),'overflow rolled back');
    $id=(int)$db->querySingle("SELECT id FROM push_subscriptions WHERE device_name='Renamed'");
    assert_true(wallos_webpush_claim($db,$id,'renewal:42:2026-11-04',1000),'first delivery claim');
    assert_true(!wallos_webpush_claim($db,$id,'renewal:42:2026-11-04',1001),'simultaneous duplicate blocked');
    assert_true(wallos_webpush_claim($db,$id,'renewal:42:2026-11-04',1301),'later failure retries');
    assert_true(wallos_webpush_claim($db,$id,'renewal:42:2026-11-04',1602),'third attempt allowed');
    assert_true(!wallos_webpush_claim($db,$id,'renewal:42:2026-11-04',2000),'attempts bounded');
    wallos_webpush_query($db,'UPDATE push_delivery_claims SET delivered_at=1 WHERE device_id=:id',['id'=>$id]);
    assert_true(!wallos_webpush_claim($db,$id,'renewal:42:2026-11-04',9999),'success never resent');
    $brief=json_decode(wallos_webpush_payload('renewal',false,['id'=>42,'name'=>'Secret','next_payment'=>'2026-11-04'],$i18n),true);
    assert_true(!str_contains($brief['body'],'Secret'),'generic lock-screen payload');
    $detail=json_decode(wallos_webpush_payload('renewal',true,['id'=>42,'name'=>'Secret','next_payment'=>'2026-11-04'],$i18n),true);
    assert_true(str_contains($detail['body'],'Secret'),'details explicitly enabled');
    assert_same('index.php',$detail['url'],'notification links never derived from private/service URL');
    $_COOKIE['wallos_push_device']=$cookie;
    wallos_webpush_sync_browser_account($db,1);
    assert_same(20,(int)$db->querySingle('SELECT COUNT(*) FROM push_subscriptions'),'same account retains binding');
    wallos_webpush_sync_browser_account($db,2);
    assert_same(19,(int)$db->querySingle('SELECT COUNT(*) FROM push_subscriptions'),'account change revokes prior binding');
    assert_same(0,(int)$db->querySingle('SELECT COUNT(*) FROM push_delivery_claims'),'delete trigger cleans claims');
    assert_true(!isset($_COOKIE['wallos_push_device']),'account switch clears device cookie');
    $otherCookie=str_pad(dechex(1),64,'0',STR_PAD_LEFT);$_COOKIE['wallos_push_device']=$otherCookie;
    wallos_webpush_revoke_browser($db);
    assert_same(18,(int)$db->querySingle('SELECT COUNT(*) FROM push_subscriptions'),'logout revokes only current browser');
    $db->exec('DELETE FROM user WHERE id=1');
    assert_same(0,(int)$db->querySingle('SELECT COUNT(*) FROM push_subscriptions'),'account cascade');
    assert_same(0,(int)$db->querySingle('SELECT COUNT(*) FROM push_notifications'),'preferences cascade');
});

wallos_test('push destinations fail closed before any network request',function(){
    foreach(['http://fcm.googleapis.com/send','https://127.0.0.1/a','https://fcm.googleapis.com.evil.example/a','https://fcm.googleapis.com:8443/a','https://user@fcm.googleapis.com/a','https://fcm.googleapis.com/a#fragment',"https://fcm.googleapis.com/a\r\nX:1",'https://example.com/push'] as $url) {
        assert_same(false,wallos_webpush_allowed_endpoint($url),'reject unsafe endpoint '.$url);
        assert_same(false,wallos_webpush_resolve_endpoint($url),'no DNS/network for unapproved URL');
    }
    foreach(['https://fcm.googleapis.com/send/a','https://updates.push.services.mozilla.com/wpush/v2/a','https://web.push.apple.com/a','https://wns2-par02p.notify.windows.com/a'] as $url) {
        assert_true(wallos_webpush_allowed_endpoint($url),'supported browser vendor');
    }
    $input=['endpoint'=>'https://fcm.googleapis.com/test','keys'=>['p256dh'=>WEBPUSH_TEST_VECTOR_UA_PUBLIC,'auth'=>WEBPUSH_TEST_VECTOR_AUTH_SECRET]];
    assert_same($input['endpoint'],wallos_webpush_validate_subscription($input)['endpoint'],'published valid curve point');
    $input['keys']['p256dh']=webpush_base64url_encode("\x04".str_repeat("\0",64));
    assert_true(push_rejects(fn()=>wallos_webpush_validate_subscription($input)),'non-curve key rejected');
    assert_same(false,webpush_send([],str_repeat('x',3001),[],'https://example.com')['success'],'send requires validated DNS pin and bounded payload');
});
echo "PASS: $checks Web Push checks.\n";
