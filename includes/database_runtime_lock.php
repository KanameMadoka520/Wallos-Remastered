<?php

function wallos_database_runtime_lock_paths($databaseFile)
{
    $databaseFile = (string) $databaseFile;
    $projectRoot = dirname(dirname($databaseFile));
    $maintenanceFile = trim((string) getenv('WALLOS_DATABASE_MAINTENANCE_FILE'));
    if ($maintenanceFile === '') {
        $maintenanceFile = $projectRoot . '/.tmp/database-maintenance.lock';
    }

    return [
        'lock' => dirname($databaseFile) . '/.wallos-runtime.lock',
        'maintenance' => $maintenanceFile,
    ];
}

function wallos_database_release_shared_runtime_lock()
{
    global $wallosDatabaseSharedRuntimeLock;

    if (is_resource($wallosDatabaseSharedRuntimeLock)) {
        @flock($wallosDatabaseSharedRuntimeLock, LOCK_UN);
        @fclose($wallosDatabaseSharedRuntimeLock);
    }
    $wallosDatabaseSharedRuntimeLock = null;
}

function wallos_database_acquire_shared_runtime_lock($databaseFile, $timeoutMilliseconds = 5000)
{
    global $wallosDatabaseSharedRuntimeLock;

    if (is_resource($wallosDatabaseSharedRuntimeLock)) {
        return $wallosDatabaseSharedRuntimeLock;
    }

    $paths = wallos_database_runtime_lock_paths($databaseFile);
    if (is_file($paths['maintenance']) || is_link($paths['maintenance'])) {
        throw new RuntimeException('Database maintenance is in progress.');
    }

    $handle = @fopen($paths['lock'], 'c');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the database runtime lock.');
    }

    $deadline = microtime(true) + (max(1, (int) $timeoutMilliseconds) / 1000);
    do {
        if (@flock($handle, LOCK_SH | LOCK_NB)) {
            if (is_file($paths['maintenance']) || is_link($paths['maintenance'])) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
                throw new RuntimeException('Database maintenance is in progress.');
            }

            $wallosDatabaseSharedRuntimeLock = $handle;
            return $handle;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    @fclose($handle);
    throw new RuntimeException('Timed out waiting for the database runtime lock.');
}

function wallos_database_acquire_exclusive_runtime_lock($databaseFile, $timeoutMilliseconds = 30000)
{
    $paths = wallos_database_runtime_lock_paths($databaseFile);
    $maintenanceDirectory = dirname($paths['maintenance']);
    if (!is_dir($maintenanceDirectory)
        && !mkdir($maintenanceDirectory, 0770, true)
        && !is_dir($maintenanceDirectory)) {
        throw new RuntimeException('Unable to create the database maintenance directory.');
    }

    $markerHandle = @fopen($paths['maintenance'], 'x');
    if ($markerHandle === false) {
        throw new RuntimeException('Another database maintenance operation is already in progress.');
    }
    fwrite($markerHandle, (string) getmypid() . PHP_EOL);
    fflush($markerHandle);
    fclose($markerHandle);
    @chmod($paths['maintenance'], 0660);

    wallos_database_release_shared_runtime_lock();

    $handle = @fopen($paths['lock'], 'c');
    if ($handle === false) {
        @unlink($paths['maintenance']);
        throw new RuntimeException('Unable to open the database runtime lock.');
    }

    $deadline = microtime(true) + (max(1, (int) $timeoutMilliseconds) / 1000);
    do {
        if (@flock($handle, LOCK_EX | LOCK_NB)) {
            return [
                'handle' => $handle,
                'maintenance' => $paths['maintenance'],
            ];
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    @fclose($handle);
    @unlink($paths['maintenance']);
    throw new RuntimeException('Timed out waiting for active database requests to finish.');
}

function wallos_database_release_exclusive_runtime_lock($lock)
{
    if (!is_array($lock)) {
        return;
    }

    $maintenanceFile = (string) ($lock['maintenance'] ?? '');
    if ($maintenanceFile !== '' && empty($lock['retain_maintenance'])) {
        @unlink($maintenanceFile);
    }

    $handle = $lock['handle'] ?? null;
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

?>
