<?php

$projectRoot = dirname(__DIR__, 2);

// Migrations run from the container entrypoint through CLI.  A browser may
// only invoke this endpoint when an authenticated administrator is present.
if (PHP_SAPI !== 'cli') {
    require_once $projectRoot . '/includes/connect_endpoint.php';
    if ((int) ($userId ?? 0) !== 1) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'message' => 'Administrator access required.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
} 

function errorHandler($severity, $message, $file, $line)
{
    throw new ErrorException($message, 0, $severity, $file, $line);
}

// Set the custom error handler
set_error_handler('errorHandler');
chdir($projectRoot);
/** @var \SQLite3 $db */
if (PHP_SAPI === 'cli') {
    try {
        require_once $projectRoot . '/includes/connect_endpoint_crontabs.php';
    } catch (Exception $e) {
        require_once $projectRoot . '/includes/connect_endpoint.php';
    }
}
restore_error_handler();


$completedMigrations = [];

$migrationTableExists = $db
    ->query('SELECT name FROM sqlite_master WHERE type="table" AND name="migrations"')
    ->fetchArray(SQLITE3_ASSOC) !== false;

if ($migrationTableExists) {
    $migrationQuery = $db->query('SELECT migration FROM migrations');
    while ($row = $migrationQuery->fetchArray(SQLITE3_ASSOC)) {
        $completedMigrations[] = $row['migration'];
    }
}

$allMigrations = glob($projectRoot . '/migrations/*.php');

$allMigrations = array_map(function ($migration) {
    return 'migrations/' . basename($migration);
}, $allMigrations);

$completedMigrations = array_map(function ($migration) {
    return str_replace('../../', '', $migration);
}, $completedMigrations);

$requiredMigrations = array_diff($allMigrations, $completedMigrations);

if (count($requiredMigrations) === 0) {
    echo "No migrations to run.\n";
}

foreach ($requiredMigrations as $migration) {
    $migrationPath = $projectRoot . '/' . $migration;
    if (!file_exists($migrationPath)) {
        continue;
    }
    require_once $migrationPath;

    $stmtInsert = $db->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
    $stmtInsert->bindParam(':migration', $migration, SQLITE3_TEXT);
    $stmtInsert->execute();

    echo sprintf("Migration %s completed successfully.\n", $migration);
}
