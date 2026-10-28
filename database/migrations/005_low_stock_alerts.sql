-- Automated low-stock alerts for aklAAAt!; safe for manual application after backup.
-- Stock belongs to book_editions. The existing per-edition threshold column is
-- retained; inventory_settings supplies the authorized global alert threshold.

CREATE TABLE IF NOT EXISTS inventory_settings (
    setting_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 5,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_inventory_settings_singleton CHECK (setting_id = 1),
    CONSTRAINT fk_inventory_settings_user FOREIGN KEY (updated_by) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO inventory_settings (setting_id, low_stock_threshold, updated_by)
VALUES (1, 5, NULL);

CREATE TABLE IF NOT EXISTS low_stock_alerts (
    alert_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    edition_id INT UNSIGNED NOT NULL,
    book_title VARCHAR(255) NOT NULL,
    edition_format VARCHAR(20) NOT NULL,
    current_stock INT UNSIGNED NOT NULL,
    threshold_at_detection INT UNSIGNED NOT NULL,
    status ENUM('active', 'resolved') NOT NULL DEFAULT 'active',
    active_edition_id INT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN edition_id ELSE NULL END) STORED,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    UNIQUE KEY uq_low_stock_one_active_edition (active_edition_id),
    KEY idx_low_stock_status_created (status, created_at),
    KEY idx_low_stock_edition_created (edition_id, created_at),
    CONSTRAINT fk_low_stock_alert_edition FOREIGN KEY (edition_id) REFERENCES book_editions(edition_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Initialize active alerts for existing editions already at or below the default.
INSERT IGNORE INTO low_stock_alerts
    (edition_id, book_title, edition_format, current_stock, threshold_at_detection, status)
SELECT e.edition_id, b.title, e.format, e.stock_quantity, s.low_stock_threshold, 'active'
  FROM book_editions e
  JOIN books b ON b.book_id = e.book_id
  JOIN inventory_settings s ON s.setting_id = 1
 WHERE e.stock_quantity <= s.low_stock_threshold;
