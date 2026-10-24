-- PayMongo hosted checkout support. This migration preserves existing orders
-- and payment rows. Back up and inspect the local schema before applying.

SET @audit_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'payment_method') = 0,
    'ALTER TABLE orders ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT ''cod''',
    'SELECT 1'
);
PREPARE paymongo_stmt FROM @audit_sql; EXECUTE paymongo_stmt; DEALLOCATE PREPARE paymongo_stmt;

SET @audit_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'payment_status') = 0,
    'ALTER TABLE orders ADD COLUMN payment_status ENUM(''not_required'',''pending'',''paid'',''failed'',''cancelled'',''expired'') NOT NULL DEFAULT ''not_required''',
    'SELECT 1'
);
PREPARE paymongo_stmt FROM @audit_sql; EXECUTE paymongo_stmt; DEALLOCATE PREPARE paymongo_stmt;

-- VARCHAR retains existing gateway values while adding the PayMongo provider.
ALTER TABLE payments MODIFY COLUMN gateway VARCHAR(30) NOT NULL;
ALTER TABLE payments MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'pending';

SET @audit_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'manual_review_required') = 0,
    'ALTER TABLE payments ADD COLUMN manual_review_required TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
    'SELECT 1'
);
PREPARE paymongo_stmt FROM @audit_sql; EXECUTE paymongo_stmt; DEALLOCATE PREPARE paymongo_stmt;

SET @audit_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'payment_method') = 0,
    'ALTER TABLE payments ADD COLUMN payment_method VARCHAR(30) NULL AFTER gateway',
    'SELECT 1'
);
PREPARE paymongo_stmt FROM @audit_sql; EXECUTE paymongo_stmt; DEALLOCATE PREPARE paymongo_stmt;

SET @audit_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'checkout_session_id') = 0,
    'ALTER TABLE payments ADD COLUMN checkout_session_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL',
    'SELECT 1'
);
PREPARE paymongo_stmt FROM @audit_sql; EXECUTE paymongo_stmt; DEALLOCATE PREPARE paymongo_stmt;

-- Provider identifiers are case-sensitive. Normalize an existing column too,
-- so re-running this migration after a partial/older application is consistent.
ALTER TABLE payments MODIFY COLUMN checkout_session_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL;

SET @audit_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'checkout_url') = 0,
    'ALTER TABLE payments ADD COLUMN checkout_url VARCHAR(1000) NULL',
    'SELECT 1'
);
PREPARE paymongo_stmt FROM @audit_sql; EXECUTE paymongo_stmt; DEALLOCATE PREPARE paymongo_stmt;

SET @audit_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'provider_payment_id') = 0,
    'ALTER TABLE payments ADD COLUMN provider_payment_id VARCHAR(100) NULL',
    'SELECT 1'
);
PREPARE paymongo_stmt FROM @audit_sql; EXECUTE paymongo_stmt; DEALLOCATE PREPARE paymongo_stmt;

SET @audit_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'provider_intent_id') = 0,
    'ALTER TABLE payments ADD COLUMN provider_intent_id VARCHAR(100) NULL',
    'SELECT 1'
);
PREPARE paymongo_stmt FROM @audit_sql; EXECUTE paymongo_stmt; DEALLOCATE PREPARE paymongo_stmt;

SET @audit_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'idempotency_key') = 0,
    'ALTER TABLE payments ADD COLUMN idempotency_key VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE paymongo_stmt FROM @audit_sql; EXECUTE paymongo_stmt; DEALLOCATE PREPARE paymongo_stmt;

SET @audit_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'currency') = 0,
    'ALTER TABLE payments ADD COLUMN currency CHAR(3) NOT NULL DEFAULT ''PHP''',
    'SELECT 1'
);
PREPARE paymongo_stmt FROM @audit_sql; EXECUTE paymongo_stmt; DEALLOCATE PREPARE paymongo_stmt;

SET @audit_sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payments' AND index_name = 'idx_payments_checkout_session' AND non_unique = 1) > 0,
    'ALTER TABLE payments DROP INDEX idx_payments_checkout_session, ADD UNIQUE INDEX idx_payments_checkout_session (checkout_session_id)',
    IF(
        (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payments' AND index_name = 'idx_payments_checkout_session') = 0,
        'ALTER TABLE payments ADD UNIQUE INDEX idx_payments_checkout_session (checkout_session_id)',
        'SELECT 1'
    )
);
PREPARE paymongo_stmt FROM @audit_sql; EXECUTE paymongo_stmt; DEALLOCATE PREPARE paymongo_stmt;

SET @audit_sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payments' AND index_name = 'idx_payments_idempotency') = 0,
    'ALTER TABLE payments ADD INDEX idx_payments_idempotency (idempotency_key)',
    'SELECT 1'
);
PREPARE paymongo_stmt FROM @audit_sql; EXECUTE paymongo_stmt; DEALLOCATE PREPARE paymongo_stmt;

CREATE TABLE IF NOT EXISTS payment_webhook_events (
    event_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    payment_id INT UNSIGNED NULL,
    outcome VARCHAR(20) NOT NULL DEFAULT 'processed',
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    CONSTRAINT fk_aklaaat_payment_webhook_payment
        FOREIGN KEY (payment_id) REFERENCES payments(payment_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_payment_webhook_received (received_at)
) ENGINE=InnoDB;