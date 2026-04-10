<?php

require_once __DIR__ . '/security_maintenance.php';

function wallos_get_login_rate_limit_max_attempts($db)
{
    $default = WALLOS_LOGIN_RATE_LIMIT_MAX_ATTEMPTS;
    $tableExists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='admin'") !== null;
    if (!$tableExists) {
        return $default;
    }

    $columnExists = false;
    $columns = $db->query("PRAGMA table_info(admin)");
    while ($columns && ($row = $columns->fetchArray(SQLITE3_ASSOC))) {
        if (($row['name'] ?? '') === 'login_rate_limit_max_attempts') {
            $columnExists = true;
            break;
        }
    }

    if (!$columnExists) {
        return $default;
    }

    $configured = (int) $db->querySingle('SELECT login_rate_limit_max_attempts FROM admin WHERE id = 1');
    if ($configured < 1) {
        return $default;
    }

    return $configured;
}

function wallos_login_attempts_table_exists($db)
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    $exists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='login_attempts'") !== null;
    return $exists;
}

function wallos_get_login_rate_limit_ip()
{
    $forwardedFor = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwardedFor !== '') {
        $parts = explode(',', $forwardedFor);
        $candidate = trim((string) ($parts[0] ?? ''));
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    $realIp = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP)) {
        return $realIp;
    }

    $remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remoteAddr !== '' && filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        return $remoteAddr;
    }

    return 'unknown';
}

function wallos_normalize_login_rate_limit_username($username)
{
    $username = strtolower(trim((string) $username));
    if (strlen($username) > 255) {
        $username = substr($username, 0, 255);
    }

    return $username;
}

function wallos_prune_login_attempts($db)
{
    if (!wallos_login_attempts_table_exists($db)) {
        return 0;
    }

    $db->exec("DELETE FROM login_attempts WHERE attempted_at <= datetime('now', '-" . WALLOS_LOGIN_ATTEMPT_RETENTION_DAYS . " days')");
    return (int) $db->changes();
}

function wallos_get_login_rate_limit_block($db, $ipAddress, $username)
{
    if (!wallos_login_attempts_table_exists($db)) {
        return '';
    }

    $query = "SELECT blocked_until
              FROM login_attempts
              WHERE blocked_until != ''
                AND blocked_until > datetime('now')
                AND (ip_address = :ip";

    if ($username !== '') {
        $query .= " OR username = :username";
    }

    $query .= ')
              ORDER BY blocked_until DESC
              LIMIT 1';

    $stmt = $db->prepare($query);
    $stmt->bindValue(':ip', $ipAddress, SQLITE3_TEXT);
    if ($username !== '') {
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    }
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return trim((string) ($row['blocked_until'] ?? ''));
}

function wallos_count_recent_login_failures($db, $column, $value)
{
    if (!wallos_login_attempts_table_exists($db) || $value === '') {
        return 0;
    }

    $allowedColumns = ['ip_address', 'username'];
    if (!in_array($column, $allowedColumns, true)) {
        return 0;
    }

    $query = "SELECT COUNT(*) AS count
              FROM login_attempts
              WHERE success = 0
                AND {$column} = :value
                AND attempted_at >= datetime('now', '-" . WALLOS_LOGIN_RATE_LIMIT_WINDOW_SECONDS . " seconds')";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    return (int) ($row['count'] ?? 0);
}

function wallos_record_failed_login_attempt($db, $ipAddress, $username)
{
    if (!wallos_login_attempts_table_exists($db)) {
        return '';
    }

    wallos_prune_login_attempts($db);
    $maxAttempts = wallos_get_login_rate_limit_max_attempts($db);

    $ipFailureCount = wallos_count_recent_login_failures($db, 'ip_address', $ipAddress);
    $userFailureCount = wallos_count_recent_login_failures($db, 'username', $username);

    $blockedUntil = '';
    $nextFailureCount = max($ipFailureCount, $userFailureCount) + 1;
    if ($nextFailureCount >= $maxAttempts) {
        $blockedUntil = date('Y-m-d H:i:s', time() + WALLOS_LOGIN_RATE_LIMIT_BLOCK_SECONDS);
    }

    $stmt = $db->prepare('
        INSERT INTO login_attempts (ip_address, username, success, blocked_until)
        VALUES (:ip_address, :username, 0, :blocked_until)
    ');
    $stmt->bindValue(':ip_address', $ipAddress, SQLITE3_TEXT);
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->bindValue(':blocked_until', $blockedUntil, SQLITE3_TEXT);
    $stmt->execute();

    return $blockedUntil;
}

function wallos_clear_login_attempts($db, $ipAddress, $username)
{
    if (!wallos_login_attempts_table_exists($db)) {
        return;
    }

    if ($username !== '') {
        $stmt = $db->prepare('DELETE FROM login_attempts WHERE username = :username OR ip_address = :ip_address');
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':ip_address', $ipAddress, SQLITE3_TEXT);
    } else {
        $stmt = $db->prepare('DELETE FROM login_attempts WHERE ip_address = :ip_address');
        $stmt->bindValue(':ip_address', $ipAddress, SQLITE3_TEXT);
    }

    $stmt->execute();
}

function wallos_build_login_rate_limit_message($i18n, $blockedUntil)
{
    $blockedUntil = trim((string) $blockedUntil);
    if ($blockedUntil === '') {
        return '';
    }

    return sprintf(
        translate('login_rate_limited', $i18n),
        htmlspecialchars($blockedUntil, ENT_QUOTES, 'UTF-8')
    );
}
