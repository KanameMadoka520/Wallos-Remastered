<?php

// Remastered compatibility migration: add the upstream one-time purchase
// cycle without changing any existing subscription rows. Code throughout the
// application treats id 5 as one-time, so an existing conflicting row must
// stop the migration instead of silently changing subscription semantics.
$existingCycle = $db->querySingle('SELECT id, days, name FROM cycles WHERE id = 5', true);
if (!is_array($existingCycle) || !array_key_exists('id', $existingCycle)) {
    $db->exec("INSERT INTO cycles (id, days, name) VALUES (5, 0, 'One-time')");
} elseif ((int) ($existingCycle['days'] ?? -1) !== 0
    || strcasecmp(trim((string) ($existingCycle['name'] ?? '')), 'One-time') !== 0) {
    throw new RuntimeException('Cycle id 5 already exists and is not the Wallos one-time cycle.');
}
