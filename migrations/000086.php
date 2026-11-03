<?php
// Browser batches use session authorization and their own account-bound retry
// ledger. They must never create an implicit Agent key or borrow one.
$db->exec('CREATE TABLE IF NOT EXISTS subscription_bulk_requests (
 user_id INTEGER NOT NULL REFERENCES user(id) ON DELETE CASCADE,
 request_id TEXT NOT NULL, request_hash TEXT NOT NULL, result_json TEXT NOT NULL,
 created_at INTEGER NOT NULL, PRIMARY KEY(user_id, request_id)
)');
