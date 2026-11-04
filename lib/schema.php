<?php
function schema_statements()
{
    $opt = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS settings (
            skey VARCHAR(60) NOT NULL PRIMARY KEY,
            svalue TEXT NOT NULL
        ) $opt",
        "CREATE TABLE IF NOT EXISTS admins (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL
        ) $opt",
        "CREATE TABLE IF NOT EXISTS categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            emoji VARCHAR(16) NOT NULL DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0
        ) $opt",
        "CREATE TABLE IF NOT EXISTS products (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(255) NOT NULL DEFAULT '',
            price INT UNSIGNED NOT NULL,
            emoji VARCHAR(16) NOT NULL DEFAULT '',
            image VARCHAR(80) NOT NULL DEFAULT '',
            available TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY idx_category (category_id)
        ) $opt",
        "CREATE TABLE IF NOT EXISTS offers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            type VARCHAR(10) NOT NULL,
            value INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NULL,
            starts_on DATE NULL,
            ends_on DATE NULL,
            active TINYINT(1) NOT NULL DEFAULT 1
        ) $opt",
        "CREATE TABLE IF NOT EXISTS ads (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(160) NOT NULL,
            description TEXT NOT NULL,
            image VARCHAR(80) NOT NULL DEFAULT '',
            product_id INT UNSIGNED NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL
        ) $opt",
        "CREATE TABLE IF NOT EXISTS zones (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            fee INT UNSIGNED NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1
        ) $opt",
        "CREATE TABLE IF NOT EXISTS coupons (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL UNIQUE,
            type VARCHAR(10) NOT NULL,
            value INT UNSIGNED NOT NULL,
            min_order INT UNSIGNED NOT NULL DEFAULT 0,
            max_uses INT UNSIGNED NULL,
            used_count INT UNSIGNED NOT NULL DEFAULT 0,
            expires_on DATE NULL,
            active TINYINT(1) NOT NULL DEFAULT 1
        ) $opt",
        "CREATE TABLE IF NOT EXISTS orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) NOT NULL UNIQUE,
            customer_name VARCHAR(100) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            address TEXT NOT NULL,
            zone_id INT UNSIGNED NULL,
            zone_name VARCHAR(100) NOT NULL DEFAULT '',
            delivery_fee INT UNSIGNED NOT NULL DEFAULT 0,
            subtotal INT UNSIGNED NOT NULL,
            discount INT UNSIGNED NOT NULL DEFAULT 0,
            coupon_code VARCHAR(40) NOT NULL DEFAULT '',
            total INT UNSIGNED NOT NULL,
            notes TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            cancel_requested TINYINT(1) NOT NULL DEFAULT 0,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_status (status),
            KEY idx_phone (phone),
            KEY idx_created (created_at)
        ) $opt",
        "CREATE TABLE IF NOT EXISTS order_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NULL,
            name VARCHAR(120) NOT NULL,
            unit_price INT UNSIGNED NOT NULL,
            qty INT UNSIGNED NOT NULL,
            KEY idx_order (order_id)
        ) $opt",
        order_requests_schema(),
        "CREATE TABLE IF NOT EXISTS throttle (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bucket VARCHAR(80) NOT NULL,
            created_at INT UNSIGNED NOT NULL,
            KEY idx_bucket (bucket, created_at),
            KEY idx_throttle_created (created_at)
        ) $opt",
    ];
}

function order_requests_schema()
{
    return "CREATE TABLE IF NOT EXISTS order_requests (
        token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        order_id INT UNSIGNED NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

function ensure_order_requests_table()
{
    try { db()->query('SELECT token_hash FROM order_requests LIMIT 0'); }
    catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1146) { throw $e; }
        if (db()->inTransaction()) { throw new RuntimeException('order_requests table must be initialized before a transaction'); }
        db()->exec(order_requests_schema());
    }
}

function backup_tables()
{
    return ['settings', 'categories', 'products', 'offers', 'ads', 'zones', 'coupons', 'orders', 'order_items', 'order_requests'];
}
