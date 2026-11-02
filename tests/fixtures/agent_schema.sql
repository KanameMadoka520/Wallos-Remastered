-- Schema only; no production records.
CREATE TABLE user (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL,
        email TEXT NOT NULL,
        password TEXT NOT NULL,
        main_currency INTEGER NOT NULL,
        avatar TEXT, language TEXT DEFAULT "en", budget INTEGER DEFAULT 0, totp_enabled BOOLEAN DEFAULT 0, api_key TEXT, firstname TEXT DEFAULT "", lastname TEXT DEFAULT "", oidc_sub TEXT, user_group TEXT DEFAULT "free", account_status TEXT DEFAULT "active", trash_reason TEXT DEFAULT "", trashed_at TEXT DEFAULT "", scheduled_delete_at TEXT DEFAULT "", yearly_budget REAL DEFAULT 0, period_budget REAL DEFAULT 0, budget_period_type TEXT DEFAULT 'monthly', budget_period_anchor_date TEXT DEFAULT '',
        FOREIGN KEY(main_currency) REFERENCES currencies(id)
    );
CREATE TABLE subscriptions (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        logo TEXT,
        price REAL NOT NULL,
        currency_id INTEGER,
        next_payment DATE,
        cycle INTEGER,
        frequency INTEGER,
        notes TEXT,
        payment_method_id INTEGER,
        payer_user_id INTEGER,
        category_id INTEGER,
        notify BOOLEAN DEFAULT false, url VARCHAR(255), inactive BOOLEAN DEFAULT false, notify_days_before INTEGER DEFAULT 0, user_id INTEGER DEFAULT 1, cancellation_date DATE, replacement_subscription_id INTEGER DEFAULT NULL, start_date INTEGER DEFAULT NULL, auto_renew INTEGER DEFAULT 1, detail_image TEXT DEFAULT "", detail_image_urls TEXT DEFAULT "[]", sort_order INTEGER DEFAULT 0, lifecycle_status TEXT DEFAULT 'active', trashed_at TEXT DEFAULT '', scheduled_delete_at TEXT DEFAULT '', exclude_from_stats BOOLEAN DEFAULT 0, manual_cycle_used_value_main REAL DEFAULT 0, manual_cycle_used_value_cycle_start TEXT DEFAULT '', subscription_page_id INTEGER, logo_text_color TEXT DEFAULT NULL, logo_variant TEXT DEFAULT NULL,
        FOREIGN KEY(currency_id) REFERENCES currencies(id),
        FOREIGN KEY(cycle) REFERENCES cycles(id),
        FOREIGN KEY(frequency) REFERENCES frequencies(id),
        FOREIGN KEY(payment_method_id) REFERENCES payment_methods(id),
        FOREIGN KEY(payer_user_id) REFERENCES household(id)
        FOREIGN KEY(category_id) REFERENCES categories(id)
    );
CREATE TABLE categories (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL
    , `order` INTEGER DEFAULT 0, user_id INTEGER DEFAULT 1);
CREATE TABLE payment_methods (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        icon TEXT
    , enabled BOOLEAN DEFAULT 1, `order` INTEGER DEFAULT 0, user_id INTEGER DEFAULT 1);
CREATE TABLE household (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL
    , email TEXT DEFAULT "", user_id INTEGER DEFAULT 1);
CREATE TABLE currencies (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        symbol TEXT NOT NULL,
        code TEXT NOT NULL,
        rate TEXT NOT NULL
    , user_id INTEGER DEFAULT 1);
CREATE TABLE cycles (
        id INTEGER PRIMARY KEY,
        days INTEGER NOT NULL,
        name TEXT NOT NULL
    );
CREATE TABLE frequencies (
        id INTEGER PRIMARY KEY,
        name INTEGER NOT NULL
    );
CREATE TABLE subscription_pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(user_id) REFERENCES user(id) ON DELETE CASCADE
        );
CREATE TABLE subscription_payment_records (
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
    );
CREATE TABLE subscription_price_rules (
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
    );
CREATE TABLE settings (
    dark_theme BOOLEAN DEFAULT 0,
    monthly_price BOOLEAN DEFAULT 0,
    convert_currency BOOLEAN DEFAULT 0,
    remove_background BOOLEAN DEFAULT 0
, color_theme TEXT DEFAULT 'blue', hide_disabled BOOLEAN DEFAULT 0, user_id INTEGER DEFAULT 1, disabled_to_bottom BOOLEAN DEFAULT 0, show_original_price BOOLEAN DEFAULT 0, mobile_nav BOOLEAN DEFAULT 0, show_subscription_progress BOOLEAN DEFAULT 0, decorative_background BOOLEAN DEFAULT 1, dynamic_wallpaper BOOLEAN DEFAULT 0, dynamic_wallpaper_blur BOOLEAN DEFAULT 1, subscription_display_columns INTEGER DEFAULT 1, subscription_value_visibility TEXT DEFAULT '{"invested":true,"remaining":true,"used":true}', subscription_image_layout_form TEXT DEFAULT 'focus', subscription_image_layout_detail TEXT DEFAULT 'focus', page_transition_enabled BOOLEAN DEFAULT 1, page_transition_style TEXT DEFAULT 'shutter', user_timezone TEXT DEFAULT 'Asia/Shanghai', week_starts_sunday BOOLEAN DEFAULT 0, screenshot_privacy_mode BOOLEAN DEFAULT 0, upcoming_payments_limit INTEGER DEFAULT 3);
