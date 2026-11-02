<?php
// Agent credentials are independent of the legacy user.api_key.
$db->exec('CREATE TABLE IF NOT EXISTS agent_keys (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL REFERENCES user(id) ON DELETE CASCADE,
 name TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE, token_prefix TEXT NOT NULL,
 scopes TEXT NOT NULL, expires_at INTEGER NOT NULL, revoked_at INTEGER,
 created_at INTEGER NOT NULL, last_used_at INTEGER,
 rate_limit INTEGER NOT NULL DEFAULT 120
)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_agent_keys_user ON agent_keys(user_id)');
$db->exec('CREATE TABLE IF NOT EXISTS agent_requests (
 key_id INTEGER NOT NULL REFERENCES agent_keys(id) ON DELETE CASCADE,
 request_id TEXT NOT NULL, request_hash TEXT NOT NULL, result_json TEXT NOT NULL,
 created_at INTEGER NOT NULL, PRIMARY KEY(key_id, request_id)
)');
$db->exec('CREATE TABLE IF NOT EXISTS agent_audit (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES user(id) ON DELETE CASCADE,
 key_id INTEGER, tool TEXT NOT NULL, request_id TEXT NOT NULL,
 status TEXT NOT NULL, target_id INTEGER, changed_fields TEXT NOT NULL DEFAULT \'[]\',
 before_version TEXT, after_version TEXT, created_at INTEGER NOT NULL
)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_agent_audit_user_id ON agent_audit(user_id, id)');
$db->exec('CREATE TABLE IF NOT EXISTS agent_rate_limits (
 bucket TEXT PRIMARY KEY, window_start INTEGER NOT NULL, count INTEGER NOT NULL
)');
