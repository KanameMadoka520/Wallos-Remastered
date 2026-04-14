<?php

function wallos_get_settings_table_columns($db)
{
    $columns = [];
    $result = $db->query("PRAGMA table_info('settings')");

    while ($result && ($column = $result->fetchArray(SQLITE3_ASSOC))) {
        if (!empty($column['name'])) {
            $columns[] = $column['name'];
        }
    }

    return $columns;
}

function wallos_get_default_settings_payload($userId)
{
    return [
        'dark_theme' => 0,
        'monthly_price' => 0,
        'convert_currency' => 0,
        'remove_background' => 0,
        'color_theme' => 'purple',
        'hide_disabled' => 0,
        'user_id' => (int) $userId,
        'disabled_to_bottom' => 0,
        'show_original_price' => 0,
        'mobile_nav' => 0,
        'show_subscription_progress' => 0,
        'decorative_background' => 1,
        'dynamic_wallpaper' => 0,
        'dynamic_wallpaper_blur' => 1,
        'page_transition_enabled' => 1,
        'page_transition_style' => 'bluearchive_theme',
        'subscription_display_columns' => 1,
        'subscription_value_visibility' => json_encode([
            'metrics' => true,
            'payment_records' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'subscription_image_layout_form' => 'focus',
        'subscription_image_layout_detail' => 'focus',
    ];
}

function wallos_insert_default_settings($db, $userId)
{
    $availableColumns = wallos_get_settings_table_columns($db);
    $defaults = wallos_get_default_settings_payload($userId);

    $insertColumns = [];
    $placeholders = [];
    foreach ($defaults as $column => $value) {
        if (in_array($column, $availableColumns, true)) {
            $insertColumns[] = $column;
            $placeholders[] = ':' . $column;
        }
    }

    if (empty($insertColumns)) {
        return false;
    }

    $query = sprintf(
        'INSERT INTO settings (%s) VALUES (%s)',
        implode(', ', $insertColumns),
        implode(', ', $placeholders)
    );
    $stmt = $db->prepare($query);

    foreach ($insertColumns as $column) {
        $value = $defaults[$column];
        if (is_int($value)) {
            $type = SQLITE3_INTEGER;
        } elseif (is_float($value)) {
            $type = SQLITE3_FLOAT;
        } else {
            $type = SQLITE3_TEXT;
        }

        $stmt->bindValue(':' . $column, $value, $type);
    }

    return $stmt->execute();
}
