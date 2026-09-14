<?php

// Upstream 56/57 overlap existing Remastered migrations; keep our history intact.
$columns = [
    'admin' => ['allow_standard_users_local_webhooks', 'INTEGER DEFAULT 0'],
    'settings' => ['upcoming_payments_limit', 'INTEGER DEFAULT 3'],
];
foreach ($columns as $table => [$column, $definition]) {
    if (!$db->querySingle("SELECT 1 FROM pragma_table_info('$table') WHERE name='$column'")) {
        $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}
$db->exec('UPDATE settings SET upcoming_payments_limit = 3
    WHERE upcoming_payments_limit IS NULL OR upcoming_payments_limit NOT IN (3, 5, 10, 20)');
// Notes keep Remastered's existing escaped-storage contract. Do not run upstream
// 58's decode migration: existing render/edit/export paths already decode once.
