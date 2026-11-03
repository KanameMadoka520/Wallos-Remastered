<?php
// Keep scalar primary references for legacy clients. Never replace historical payments.
foreach ([['subscription_payers', 'payer_id', 'household', 'payer_user_id'], ['subscription_categories', 'category_id', 'categories', 'category_id']] as [$table, $column, $reference, $scalar]) {
    $db->exec("CREATE TABLE IF NOT EXISTS $table (
        subscription_id INTEGER NOT NULL REFERENCES subscriptions(id) ON DELETE CASCADE,
        $column INTEGER NOT NULL REFERENCES $reference(id) ON DELETE RESTRICT,
        position INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY(subscription_id,$column)
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_{$table}_reference ON $table($column,subscription_id)");
    $db->exec("INSERT OR IGNORE INTO $table(subscription_id,$column,position) SELECT s.id,s.$scalar,0 FROM subscriptions s JOIN $reference r ON r.id=s.$scalar AND r.user_id=s.user_id WHERE s.$scalar > 0");
    // Legacy insert/update paths preserve existing secondary associations.
    $db->exec("CREATE TRIGGER IF NOT EXISTS {$table}_insert AFTER INSERT ON subscriptions BEGIN INSERT OR IGNORE INTO $table(subscription_id,$column,position) SELECT NEW.id,NEW.$scalar,0 FROM $reference WHERE id=NEW.$scalar AND user_id=NEW.user_id; END");
    $db->exec("CREATE TRIGGER IF NOT EXISTS {$table}_primary AFTER UPDATE OF $scalar ON subscriptions WHEN OLD.$scalar IS NOT NEW.$scalar BEGIN INSERT OR IGNORE INTO $table(subscription_id,$column,position) SELECT NEW.id,NEW.$scalar,0 FROM $reference WHERE id=NEW.$scalar AND user_id=NEW.user_id; END");
    // Some legacy maintenance paths deliberately disable foreign keys.
    $db->exec("CREATE TRIGGER IF NOT EXISTS {$table}_delete AFTER DELETE ON subscriptions BEGIN DELETE FROM $table WHERE subscription_id=OLD.id; END");
}
