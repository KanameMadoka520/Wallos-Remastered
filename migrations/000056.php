<?php

$db->exec('
    CREATE TABLE IF NOT EXISTS subscription_price_rules (
        id INTEGER PRIMARY KEY,
        subscription_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        rule_type TEXT NOT NULL,
        price REAL NOT NULL,
        currency_id INTEGER NOT NULL,
        start_date TEXT DEFAULT "",
        end_date TEXT DEFAULT "",
        max_cycles INTEGER DEFAULT 0,
        priority INTEGER DEFAULT 1,
        note TEXT DEFAULT "",
        enabled BOOLEAN DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )
');

$db->exec('CREATE INDEX IF NOT EXISTS idx_subscription_price_rules_subscription_priority ON subscription_price_rules(subscription_id, priority)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_subscription_price_rules_user_subscription ON subscription_price_rules(user_id, subscription_id)');
