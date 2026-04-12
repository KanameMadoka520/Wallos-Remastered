<?php

$db->exec('
    CREATE TABLE IF NOT EXISTS subscription_payment_records (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        subscription_id INTEGER NOT NULL,
        due_date TEXT NOT NULL,
        paid_at TEXT NOT NULL,
        amount_original REAL NOT NULL,
        currency_id INTEGER NOT NULL,
        currency_code_snapshot TEXT NOT NULL,
        main_currency_code_snapshot TEXT NOT NULL,
        fx_rate_to_main_snapshot REAL NOT NULL DEFAULT 1,
        amount_main_snapshot REAL NOT NULL,
        payment_method_id INTEGER DEFAULT 0,
        status TEXT NOT NULL DEFAULT "paid",
        note TEXT DEFAULT "",
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )
');

$db->exec('CREATE INDEX IF NOT EXISTS idx_subscription_payment_records_user_paid_at ON subscription_payment_records(user_id, paid_at)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_subscription_payment_records_subscription_paid_at ON subscription_payment_records(subscription_id, paid_at)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_subscription_payment_records_subscription_due_date ON subscription_payment_records(subscription_id, due_date)');
