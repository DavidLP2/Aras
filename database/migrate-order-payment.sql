ALTER TABLE `orders`
    ADD COLUMN payment_status ENUM('not_applicable', 'unpaid', 'paid', 'expired', 'paid_late')
        NOT NULL DEFAULT 'not_applicable' AFTER status,
    ADD COLUMN payment_expires_at DATETIME NULL DEFAULT NULL AFTER payment_status,
    ADD COLUMN payment_transaction_id VARCHAR(64) NULL DEFAULT NULL AFTER payment_expires_at,
    ADD KEY idx_orders_payment_expiry (payment_status, payment_expires_at);
