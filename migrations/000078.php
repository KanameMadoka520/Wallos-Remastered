<?php

// Read-heavy subscription pages and notification jobs all scope by user.
// These indexes speed up those reads without changing any rows or constraints.
$db->exec('CREATE INDEX IF NOT EXISTS idx_subscriptions_user_inactive_next_payment
           ON subscriptions (user_id, inactive, next_payment)');

$db->exec('CREATE INDEX IF NOT EXISTS idx_subscriptions_user_notify_inactive
           ON subscriptions (user_id, notify, inactive)');

?>
