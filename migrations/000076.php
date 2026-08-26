<?php

// Remastered compatibility migration: add the upstream one-time purchase
// cycle without changing any existing subscription rows.
$db->exec("INSERT OR IGNORE INTO cycles (id, days, name) VALUES (5, 0, 'One-time')");

