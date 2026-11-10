CREATE DATABASE IF NOT EXISTS `aras` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `aras`;

CREATE TABLE IF NOT EXISTS `Usuarios` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(40) NOT NULL,
    full_name VARCHAR(120) NULL,
    email VARCHAR(254) NULL,
    phone VARCHAR(30) NULL,
    national_id VARCHAR(15) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'Superadministrativo',
    permissions TEXT NULL,
    superadmin_slot TINYINT GENERATED ALWAYS AS (
        CASE WHEN role = 'Superadministrativo' THEN 1 ELSE NULL END
    ) STORED,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_username (username),
    UNIQUE KEY uq_usuarios_email (email),
    UNIQUE KEY uq_usuarios_single_superadmin (superadmin_slot),
    KEY idx_usuarios_active_role (is_active, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (token_hash),
    KEY idx_password_reset_user_created (user_id, created_at),
    CONSTRAINT fk_password_reset_user
        FOREIGN KEY (user_id) REFERENCES `Usuarios` (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customers` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(24) NOT NULL,
    status ENUM('pending', 'sold', 'not_concluded') NOT NULL DEFAULT 'pending',
    payment_status ENUM('not_applicable', 'unpaid', 'paid', 'expired', 'paid_late') NOT NULL DEFAULT 'not_applicable',
    payment_expires_at DATETIME NULL DEFAULT NULL,
    payment_transaction_id VARCHAR(64) NULL DEFAULT NULL,
    customer_name VARCHAR(120) NULL,
    customer_phone VARCHAR(30) NULL,
    customer_email VARCHAR(254) NULL,
    delivery_method ENUM('delivery', 'pickup') NOT NULL,
    city VARCHAR(120) NOT NULL,
    delivery_zone ENUM('medellin', 'antioquia') NOT NULL DEFAULT 'medellin',
    delivery_address VARCHAR(255) NULL,
    delivery_latitude DECIMAL(10,7) NULL,
    delivery_longitude DECIMAL(10,7) NULL,
    subtotal INT UNSIGNED NOT NULL,
    shipping INT UNSIGNED NOT NULL DEFAULT 0,
    total INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    reviewed_by VARCHAR(40) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_reference (reference),
    KEY idx_orders_status_created (status, created_at),
    KEY idx_orders_customer_email_created (customer_email, created_at),
    KEY idx_orders_payment_expiry (payment_status, payment_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_items` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id CHAR(32) NOT NULL,
    product_name VARCHAR(120) NOT NULL,
    unit_price INT UNSIGNED NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_order_items_order (order_id),
    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id) REFERENCES `orders` (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
