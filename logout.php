<?php
require_once 'includes/connect.php';
require_once 'includes/request_security.php';
require_once 'includes/oidc_settings.php';
$secondsInMonth = 30 * 24 * 60 * 60;
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(wallos_build_session_cookie_params($secondsInMonth));
    session_start();
}

$logoutOIDC = false;

// Check if user is logged in with OIDC
if (isset($_SESSION['from_oidc']) && $_SESSION['from_oidc'] === true) {
    $logoutOIDC = true;
    $oidcConfiguration = wallos_get_effective_oidc_configuration($db);
    $oidcSettings = $oidcConfiguration['settings'];
    $logoutUrl = $oidcSettings['logout_url'] ?? '';
}

// get token from cookie to remove from DB
if (isset($_SESSION['token'])) {
    $token = $_SESSION['token'];
    $sql = "DELETE FROM login_tokens WHERE token = :token AND user_id = :userId";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':token', $token, SQLITE3_TEXT);
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}
$_SESSION = array();
session_destroy();
$cookieExpire = time() - 3600;
setcookie('wallos_login', '', wallos_build_cookie_options($cookieExpire, ['httponly' => true]));
$db->close();

if ($logoutOIDC && !empty($logoutUrl) && wallos_oidc_is_http_url($logoutUrl)) {
    $logoutTarget = rtrim($logoutUrl, '&');
    $separator = substr($logoutTarget, -1) === '?'
        ? ''
        : (strpos($logoutUrl, '?') === false ? '?' : '&');
    $logoutQuery = http_build_query([
        'post_logout_redirect_uri' => (string) ($oidcSettings['redirect_url'] ?? ''),
    ]);
    header('Location: ' . $logoutTarget . $separator . $logoutQuery);
    exit();
}

?>
<!DOCTYPE html>
<html>
<head>
<script>
  async function clearAndRedirect() {
    if ('caches' in window) {
      const keys = await caches.keys();
      const wallosCachePrefixes = ['pages-cache-v', 'static-cache-v', 'logos-cache-v'];
      await Promise.all(
        keys
          .filter((key) => wallosCachePrefixes.some((prefix) => key.startsWith(prefix)))
          .map((key) => caches.delete(key))
      );
    }
    sessionStorage.removeItem('sw_prefetched');
    window.location.href = '.';
  }
  clearAndRedirect();
</script>
</head>
<body></body>
</html>
<?php
exit();
