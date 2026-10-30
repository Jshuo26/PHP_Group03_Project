-- Hashed, expiring password-reset links and privacy-preserving request throttling.
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    reset_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    email_lookup_hash CHAR(64) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    request_ip_hash CHAR(64) NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    UNIQUE KEY uk_password_reset_token_hash (token_hash),
    KEY idx_password_reset_email_requested (email_lookup_hash, requested_at),
    KEY idx_password_reset_ip_requested (request_ip_hash, requested_at),
    KEY idx_password_reset_expiry (expires_at),
    KEY idx_password_reset_user (user_id),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
