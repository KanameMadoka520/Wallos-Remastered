<?php

function wallos_request_log_safety_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wallos_request_log_safety_remove_tree($path)
{
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            wallos_request_log_safety_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
}

function wallos_request_log_safety_run_child($runnerPath, $requestLogsPath, $replacementDatabase = null)
{
    $command = [PHP_BINARY, $runnerPath, $requestLogsPath];
    if ($replacementDatabase !== null) {
        $command[] = $replacementDatabase;
    }
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start request-log shutdown fixture.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    wallos_request_log_safety_assert(
        $exitCode === 0,
        'Request-log shutdown fixture failed: ' . trim($stdout . ' ' . $stderr)
    );
}

$testRoot = sys_get_temp_dir() . '/wallos-request-log-safety-' . bin2hex(random_bytes(8));
$projectRoot = $testRoot . '/app';
$includesRoot = $projectRoot . '/includes';
$databaseRoot = $projectRoot . '/db';
$databasePath = $databaseRoot . '/wallos.db';
$journalPath = $databaseRoot . '/.wallos-restore-transaction';

try {
    wallos_request_log_safety_assert(
        mkdir($includesRoot, 0770, true) && mkdir($databaseRoot, 0770, true),
        'Cannot create request-log safety fixture directories.'
    );
    foreach (['database_runtime_lock.php', 'request_logs.php'] as $fileName) {
        wallos_request_log_safety_assert(
            copy(__DIR__ . '/../includes/' . $fileName, $includesRoot . '/' . $fileName),
            'Cannot copy request-log safety fixture: ' . $fileName
        );
    }

    $runnerPath = $testRoot . '/shutdown-runner.php';
    wallos_request_log_safety_assert(
        file_put_contents(
            $runnerPath,
            "<?php\nrequire \$argv[1];\nhttp_response_code(204);\n"
                . "wallos_register_request_log_completion_update(1, microtime(true) - 0.01);\n"
                . "if (isset(\$argv[2])) {\n"
                . "    \$live = wallos_request_log_database_path();\n"
                . "    rename(\$live, \$live . '.pre-restore');\n"
                . "    rename(\$argv[2], \$live);\n"
                . "}\n"
        ) !== false,
        'Cannot create request-log shutdown runner.'
    );

    wallos_request_log_safety_assert(
        file_put_contents($journalPath, '{"phase":"ROLLBACK_INCOMPLETE"}') !== false,
        'Cannot create durable restore journal fixture.'
    );
    wallos_request_log_safety_run_child($runnerPath, $includesRoot . '/request_logs.php');
    wallos_request_log_safety_assert(
        !file_exists($databasePath),
        'Shutdown request logging created a live database beside an incomplete restore journal.'
    );

    unlink($journalPath);
    wallos_request_log_safety_run_child($runnerPath, $includesRoot . '/request_logs.php');
    wallos_request_log_safety_assert(
        !file_exists($databasePath),
        'Shutdown request logging recreated a missing live database.'
    );

    $database = new SQLite3($databasePath);
    $database->enableExceptions(true);
    $database->exec('CREATE TABLE request_logs (
        id INTEGER PRIMARY KEY,
        duration_ms INTEGER,
        status_code INTEGER,
        completed_at DATETIME
    )');
    $database->exec('INSERT INTO request_logs (id) VALUES (1)');
    $database->close();

    file_put_contents($journalPath, '{"phase":"ROLLBACK_INCOMPLETE"}');
    wallos_request_log_safety_run_child($runnerPath, $includesRoot . '/request_logs.php');
    $database = new SQLite3($databasePath, SQLITE3_OPEN_READONLY);
    $blockedRow = $database->querySingle(
        'SELECT duration_ms, status_code, completed_at FROM request_logs WHERE id = 1',
        true
    );
    $database->close();
    wallos_request_log_safety_assert(
        $blockedRow['duration_ms'] === null
            && $blockedRow['status_code'] === null
            && $blockedRow['completed_at'] === null,
        'Shutdown request logging wrote through an incomplete restore journal.'
    );

    unlink($journalPath);
    wallos_request_log_safety_run_child($runnerPath, $includesRoot . '/request_logs.php');
    $database = new SQLite3($databasePath, SQLITE3_OPEN_READONLY);
    $completedRow = $database->querySingle(
        'SELECT duration_ms, status_code, completed_at FROM request_logs WHERE id = 1',
        true
    );
    $database->close();
    wallos_request_log_safety_assert(
        (int) $completedRow['duration_ms'] >= 0
            && (int) $completedRow['status_code'] === 204
            && trim((string) $completedRow['completed_at']) !== '',
        'Shutdown request logging no longer updates a healthy existing database.'
    );

    $database = new SQLite3($databasePath, SQLITE3_OPEN_READWRITE);
    $database->exec('UPDATE request_logs SET duration_ms = NULL, status_code = NULL, completed_at = NULL WHERE id = 1');
    $database->close();
    $replacementPath = $databaseRoot . '/replacement.db';
    $replacement = new SQLite3($replacementPath);
    $replacement->enableExceptions(true);
    $replacement->exec('CREATE TABLE request_logs (
        id INTEGER PRIMARY KEY,
        duration_ms INTEGER,
        status_code INTEGER,
        completed_at DATETIME
    )');
    $replacement->exec("INSERT INTO request_logs
        (id, duration_ms, status_code, completed_at)
        VALUES (1, 777, 299, 'replacement-sentinel')");
    $replacement->close();

    wallos_request_log_safety_run_child(
        $runnerPath,
        $includesRoot . '/request_logs.php',
        $replacementPath
    );
    $database = new SQLite3($databasePath, SQLITE3_OPEN_READONLY);
    $replacementRow = $database->querySingle(
        'SELECT duration_ms, status_code, completed_at FROM request_logs WHERE id = 1',
        true
    );
    $database->close();
    wallos_request_log_safety_assert(
        (int) $replacementRow['duration_ms'] === 777
            && (int) $replacementRow['status_code'] === 299
            && (string) $replacementRow['completed_at'] === 'replacement-sentinel',
        'A shutdown callback from the old database updated a same-ID row after restore cutover.'
    );

    echo "Request-log completion restore safety test passed.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    wallos_request_log_safety_remove_tree($testRoot);
}

?>
