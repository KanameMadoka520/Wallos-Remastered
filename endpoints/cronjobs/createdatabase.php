<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$databaseFile = __DIR__ . '/../../db/wallos.db';
$databaseDirectory = dirname($databaseFile);
$lockFile = $databaseDirectory . '/.wallos-create.lock';

function wallos_bootstrap_assert_existing_database($databaseFile)
{
    clearstatcache(true, $databaseFile);

    if (is_link($databaseFile) || !is_file($databaseFile)) {
        throw new RuntimeException('wallos.db exists but is not a regular file.');
    }

    $size = filesize($databaseFile);
    if ($size === false || $size <= 0) {
        throw new RuntimeException('wallos.db exists but is empty. Refusing to replace it.');
    }
}

function wallos_bootstrap_cleanup_temporary_database($temporaryFile)
{
    if (!is_string($temporaryFile) || $temporaryFile === '') {
        return;
    }

    foreach ([$temporaryFile, $temporaryFile . '-journal', $temporaryFile . '-wal', $temporaryFile . '-shm'] as $path) {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }
}

function wallos_bootstrap_assert_database(SQLite3 $db)
{
    $integrityResult = $db->query('PRAGMA integrity_check');
    $integrityRows = 0;
    while ($row = $integrityResult->fetchArray(SQLITE3_NUM)) {
        $integrityRows++;
        if (($row[0] ?? '') !== 'ok') {
            throw new RuntimeException('New database failed SQLite integrity_check.');
        }
    }
    if ($integrityRows === 0) {
        throw new RuntimeException('New database returned no SQLite integrity_check result.');
    }

    $foreignKeyResult = $db->query('PRAGMA foreign_key_check');
    if ($foreignKeyResult->fetchArray(SQLITE3_NUM) !== false) {
        throw new RuntimeException('New database contains foreign key violations.');
    }

    foreach (['user', 'subscriptions', 'categories', 'currencies', 'payment_methods', 'cycles', 'frequencies', 'notifications'] as $table) {
        $stmt = $db->prepare('SELECT 1 FROM sqlite_master WHERE type = :type AND name = :name LIMIT 1');
        $stmt->bindValue(':type', 'table', SQLITE3_TEXT);
        $stmt->bindValue(':name', $table, SQLITE3_TEXT);
        $result = $stmt->execute();
        if ($result->fetchArray(SQLITE3_NUM) === false) {
            throw new RuntimeException('New database is missing required table: ' . $table);
        }
    }

    $expectedSeedCounts = [
        'categories' => 17,
        'currencies' => 34,
        'payment_methods' => 31,
        'cycles' => 4,
        'frequencies' => 31,
    ];
    foreach ($expectedSeedCounts as $table => $expectedCount) {
        $count = (int) $db->querySingle('SELECT COUNT(*) FROM ' . $table);
        if ($count !== $expectedCount) {
            throw new RuntimeException('New database has an unexpected seed count for ' . $table . '.');
        }
    }
}

try {
    if (file_exists($databaseFile) || is_link($databaseFile)) {
        wallos_bootstrap_assert_existing_database($databaseFile);
        echo "Database already exists; bootstrap made no changes.\n";
        exit(0);
    }

    if (!is_dir($databaseDirectory) && !mkdir($databaseDirectory, 0770, true) && !is_dir($databaseDirectory)) {
        throw new RuntimeException('Unable to create the database directory.');
    }
    if (!is_writable($databaseDirectory)) {
        throw new RuntimeException('The database directory is not writable.');
    }

    $lockHandle = fopen($lockFile, 'c');
    if ($lockHandle === false) {
        throw new RuntimeException('Unable to open the database creation lock.');
    }

    try {
        if (!flock($lockHandle, LOCK_EX)) {
            throw new RuntimeException('Unable to acquire the database creation lock.');
        }

        clearstatcache(true, $databaseFile);
        if (file_exists($databaseFile) || is_link($databaseFile)) {
            wallos_bootstrap_assert_existing_database($databaseFile);
            echo "Database already exists; bootstrap made no changes.\n";
        } else {
            echo "Database does not exist. Creating it safely...\n";

            $temporaryFile = tempnam($databaseDirectory, '.wallos-create-');
            if ($temporaryFile === false
                || realpath(dirname($temporaryFile)) !== realpath($databaseDirectory)) {
                wallos_bootstrap_cleanup_temporary_database($temporaryFile);
                throw new RuntimeException('Unable to create a temporary database in the data directory.');
            }

            $db = null;
            $transactionStarted = false;
            try {
                $db = new SQLite3($temporaryFile, SQLITE3_OPEN_READWRITE);
                $db->enableExceptions(true);
                $db->busyTimeout(5000);
                $db->exec('PRAGMA journal_mode = DELETE');
                $db->exec('PRAGMA synchronous = FULL');
                $db->exec('PRAGMA foreign_keys = ON');
                $db->exec('BEGIN IMMEDIATE');
                $transactionStarted = true;

                $db->exec('CREATE TABLE user (
                    id INTEGER PRIMARY KEY,
                    username TEXT NOT NULL,
                    email TEXT NOT NULL,
                    password TEXT NOT NULL,
                    main_currency INTEGER NOT NULL,
                    avatar TEXT,
                    FOREIGN KEY(main_currency) REFERENCES currencies(id)
                )');

                $db->exec('CREATE TABLE payment_methods (
                    id INTEGER PRIMARY KEY,
                    name TEXT NOT NULL,
                    icon TEXT
                )');

                $db->exec('CREATE TABLE subscriptions (
                    id INTEGER PRIMARY KEY,
                    name TEXT NOT NULL,
                    logo TEXT,
                    price REAL NOT NULL,
                    currency_id INTEGER,
                    next_payment DATE,
                    cycle INTEGER,
                    frequency INTEGER,
                    notes TEXT,
                    payment_method_id INTEGER,
                    payer_user_id INTEGER,
                    category_id INTEGER,
                    notify BOOLEAN DEFAULT false,
                    FOREIGN KEY(currency_id) REFERENCES currencies(id),
                    FOREIGN KEY(cycle) REFERENCES cycles(id),
                    FOREIGN KEY(frequency) REFERENCES frequencies(id),
                    FOREIGN KEY(payment_method_id) REFERENCES payment_methods(id),
                    FOREIGN KEY(payer_user_id) REFERENCES household(id),
                    FOREIGN KEY(category_id) REFERENCES categories(id)
                )');

                $db->exec('CREATE TABLE categories (
                    id INTEGER PRIMARY KEY,
                    name TEXT NOT NULL
                )');

                $db->exec('CREATE TABLE currencies (
                    id INTEGER PRIMARY KEY,
                    name TEXT NOT NULL,
                    symbol TEXT NOT NULL,
                    code TEXT NOT NULL,
                    rate TEXT NOT NULL
                )');

                $db->exec('CREATE TABLE household (
                    id INTEGER PRIMARY KEY,
                    name TEXT NOT NULL
                )');

                $db->exec('CREATE TABLE login_tokens (
                    user_id INTEGER NOT NULL,
                    token TEXT NOT NULL,
                    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE CASCADE ON UPDATE CASCADE
                )');

                $db->exec('CREATE TABLE cycles (
                    id INTEGER PRIMARY KEY,
                    days INTEGER NOT NULL,
                    name TEXT NOT NULL
                )');

                $db->exec('CREATE TABLE frequencies (
                    id INTEGER PRIMARY KEY,
                    name INTEGER NOT NULL
                )');

                $db->exec('CREATE TABLE fixer (
                    api_key TEXT NOT NULL
                )');

                $db->exec('CREATE TABLE last_exchange_update (
                    date DATE NOT NULL
                )');

                $db->exec('CREATE TABLE last_update_next_payment_date (
                    date DATE NOT NULL
                )');

                $db->exec('CREATE TABLE notifications (
                    id INTEGER PRIMARY KEY,
                    enabled BOOLEAN DEFAULT false,
                    days INTEGER,
                    smtp_address VARCHAR(255),
                    smtp_port INTEGER,
                    smtp_username VARCHAR(255),
                    smtp_password VARCHAR(255)
                )');

                $db->exec("INSERT INTO categories (id, name) VALUES
                    (1, 'No category'),
                    (2, 'Entertainment'),
                    (3, 'Music'),
                    (4, 'Utilities'),
                    (5, 'Food & Beverages'),
                    (6, 'Health & Wellbeing'),
                    (7, 'Productivity'),
                    (8, 'Banking'),
                    (9, 'Transport'),
                    (10, 'Education'),
                    (11, 'Insurance'),
                    (12, 'Gaming'),
                    (13, 'News & Magazines'),
                    (14, 'Software'),
                    (15, 'Technology'),
                    (16, 'Cloud Services'),
                    (17, 'Charity & Donations')");

                $db->exec("INSERT INTO cycles (id, days, name) VALUES
                    (1, 1, 'Daily'),
                    (2, 7, 'Weekly'),
                    (3, 30, 'Monthly'),
                    (4, 365, 'Yearly')");

                $frequencyValues = [];
                for ($frequency = 1; $frequency <= 31; $frequency++) {
                    $frequencyValues[] = '(' . $frequency . ', ' . $frequency . ')';
                }
                $db->exec('INSERT INTO frequencies (id, name) VALUES ' . implode(', ', $frequencyValues));

                $db->exec("INSERT INTO currencies (name, symbol, code, rate) VALUES
                    ('Euro', '€', 'EUR', 1),
                    ('US Dollar', '$', 'USD', 1),
                    ('Japanese Yen', '¥', 'JPY', 1),
                    ('Bulgarian Lev', 'лв', 'BGN', 1),
                    ('Czech Republic Koruna', 'Kč', 'CZK', 1),
                    ('Danish Krone', 'kr', 'DKK', 1),
                    ('British Pound Sterling', '£', 'GBP', 1),
                    ('Hungarian Forint', 'Ft', 'HUF', 1),
                    ('Polish Zloty', 'zł', 'PLN', 1),
                    ('Romanian Leu', 'lei', 'RON', 1),
                    ('Swedish Krona', 'kr', 'SEK', 1),
                    ('Swiss Franc', 'Fr', 'CHF', 1),
                    ('Icelandic Króna', 'kr', 'ISK', 1),
                    ('Norwegian Krone', 'kr', 'NOK', 1),
                    ('Russian Ruble', '₽', 'RUB', 1),
                    ('Turkish Lira', '₺', 'TRY', 1),
                    ('Australian Dollar', '$', 'AUD', 1),
                    ('Brazilian Real', 'R$', 'BRL', 1),
                    ('Canadian Dollar', '$', 'CAD', 1),
                    ('Chinese Yuan', '¥', 'CNY', 1),
                    ('Hong Kong Dollar', 'HK$', 'HKD', 1),
                    ('Indonesian Rupiah', 'Rp', 'IDR', 1),
                    ('Israeli New Sheqel', '₪', 'ILS', 1),
                    ('Indian Rupee', '₹', 'INR', 1),
                    ('South Korean Won', '₩', 'KRW', 1),
                    ('Mexican Peso', 'Mex$', 'MXN', 1),
                    ('Malaysian Ringgit', 'RM', 'MYR', 1),
                    ('New Zealand Dollar', 'NZ$', 'NZD', 1),
                    ('Philippine Peso', '₱', 'PHP', 1),
                    ('Singapore Dollar', 'S$', 'SGD', 1),
                    ('Thai Baht', '฿', 'THB', 1),
                    ('South African Rand', 'R', 'ZAR', 1),
                    ('Ukrainian Hryvnia', '₴', 'UAH', 1),
                    ('New Taiwan Dollar', 'NT$', 'TWD', 1)");

                $db->exec("INSERT INTO payment_methods (id, name, icon) VALUES
                    (1, 'PayPal', 'paypal.png'),
                    (2, 'Credit Card', 'creditcard.png'),
                    (3, 'Bank Transfer', 'banktransfer.png'),
                    (4, 'Direct Debit', 'directdebit.png'),
                    (5, 'Money', 'money.png'),
                    (6, 'Google Pay', 'googlepay.png'),
                    (7, 'Samsung Pay', 'samsungpay.png'),
                    (8, 'Apple Pay', 'applepay.png'),
                    (9, 'Crypto', 'crypto.png'),
                    (10, 'Klarna', 'klarna.png'),
                    (11, 'Amazon Pay', 'amazonpay.png'),
                    (12, 'SEPA', 'sepa.png'),
                    (13, 'Skrill', 'skrill.png'),
                    (14, 'Sofort', 'sofort.png'),
                    (15, 'Stripe', 'stripe.png'),
                    (16, 'Affirm', 'affirm.png'),
                    (17, 'AliPay', 'alipay.png'),
                    (18, 'Elo', 'elo.png'),
                    (19, 'Facebook Pay', 'facebookpay.png'),
                    (20, 'GiroPay', 'giropay.png'),
                    (21, 'iDeal', 'ideal.png'),
                    (22, 'Union Pay', 'unionpay.png'),
                    (23, 'Interac', 'interac.png'),
                    (24, 'WeChat', 'wechat.png'),
                    (25, 'Paysafe', 'paysafe.png'),
                    (26, 'Poli', 'poli.png'),
                    (27, 'Qiwi', 'qiwi.png'),
                    (28, 'ShopPay', 'shoppay.png'),
                    (29, 'Venmo', 'venmo.png'),
                    (30, 'VeriFone', 'verifone.png'),
                    (31, 'WebMoney', 'webmoney.png')");

                $db->exec('COMMIT');
                $transactionStarted = false;
                wallos_bootstrap_assert_database($db);
                $db->close();
                $db = null;

                $directoryOwner = fileowner($databaseDirectory);
                $directoryGroup = filegroup($databaseDirectory);
                if ($directoryOwner !== false
                    && fileowner($temporaryFile) !== $directoryOwner
                    && !chown($temporaryFile, $directoryOwner)) {
                    throw new RuntimeException('Unable to set database file ownership.');
                }
                if ($directoryGroup !== false
                    && filegroup($temporaryFile) !== $directoryGroup
                    && !chgrp($temporaryFile, $directoryGroup)) {
                    throw new RuntimeException('Unable to set database file group.');
                }
                if (!chmod($temporaryFile, 0660)) {
                    throw new RuntimeException('Unable to set database file permissions.');
                }

                clearstatcache(true, $databaseFile);
                if (file_exists($databaseFile) || is_link($databaseFile)) {
                    throw new RuntimeException('wallos.db appeared during creation; refusing to replace it.');
                }
                if (!rename($temporaryFile, $databaseFile)) {
                    throw new RuntimeException('Unable to atomically install the new database.');
                }
                $temporaryFile = null;

                echo "Database created and verified successfully.\n";
            } catch (Throwable $throwable) {
                if ($db instanceof SQLite3) {
                    if ($transactionStarted) {
                        try {
                            $db->exec('ROLLBACK');
                        } catch (Throwable $rollbackError) {
                            // Preserve the original bootstrap failure.
                        }
                    }
                    try {
                        $db->close();
                    } catch (Throwable $closeError) {
                        // Preserve the original bootstrap failure.
                    }
                }
                wallos_bootstrap_cleanup_temporary_database($temporaryFile);
                throw $throwable;
            }
        }
    } finally {
        @flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Database bootstrap failed: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

?>
