
CREATE DATABASE IF NOT EXISTS aklaaat_db
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE aklaaat_db;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE users (
    user_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(150)    NOT NULL,
    email           VARCHAR(255)    NOT NULL,
    mobile_number   VARCHAR(20)     NULL,
    password_hash   VARCHAR(255)    NOT NULL,
    role            ENUM('customer','staff','admin') NOT NULL DEFAULT 'customer',
    account_status  ENUM('active','suspended')       NOT NULL DEFAULT 'active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_users_email (email)
) ENGINE=InnoDB;

CREATE TABLE addresses (
    address_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    label           VARCHAR(50)  NULL,
    street          VARCHAR(255) NOT NULL,
    city            VARCHAR(100) NOT NULL,
    province        VARCHAR(100) NOT NULL,
    zip_code        VARCHAR(10)  NOT NULL,
    is_default      BOOLEAN NOT NULL DEFAULT FALSE,
    CONSTRAINT fk_addresses_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_addresses_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE mfa_settings (
    mfa_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    method          ENUM('totp','email_otp','sms_otp') NOT NULL,
    secret_key      VARCHAR(255) NOT NULL,
    enabled_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mfa_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uk_mfa_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE otp_codes (
    otp_id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    code_hash       VARCHAR(255) NOT NULL,
    purpose         ENUM('registration','login','password_reset') NOT NULL,
    expires_at      DATETIME NOT NULL,
    used_at         DATETIME NULL,
    CONSTRAINT fk_otp_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_otp_user_purpose (user_id, purpose)
) ENGINE=InnoDB;

CREATE TABLE sessions (
    session_id      VARCHAR(128) PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    device_info     VARCHAR(255) NULL,
    ip_address      VARCHAR(45)  NULL,
    last_active_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NOT NULL,
    CONSTRAINT fk_sessions_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_sessions_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    attempt_id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NULL,
    ip_address      VARCHAR(45) NOT NULL,
    success         BOOLEAN NOT NULL,
    attempted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_login_attempts_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_login_attempts_user_time (user_id, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE locked_accounts (
    user_id         INT UNSIGNED PRIMARY KEY,
    locked_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reason          VARCHAR(255) NOT NULL,
    unlocked_by     INT UNSIGNED NULL,
    unlocked_at     DATETIME NULL,
    CONSTRAINT fk_locked_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_locked_unlocked_by
        FOREIGN KEY (unlocked_by) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE security_policy (
    policy_id                  TINYINT UNSIGNED PRIMARY KEY,
    max_failed_attempts        INT UNSIGNED NOT NULL DEFAULT 3,
    lockout_duration_minutes   INT UNSIGNED NOT NULL DEFAULT 30,
    password_min_length        INT UNSIGNED NOT NULL DEFAULT 12,
    uppercase_min_length       INT UNSIGNED NOT NULL DEFAULT 1,
    lowercase_min_length       INT UNSIGNED NOT NULL DEFAULT 1,
    number_min_length          INT UNSIGNED NOT NULL DEFAULT 1,
    special_min_length         INT UNSIGNED NOT NULL DEFAULT 1,
    session_timeout_minutes    INT UNSIGNED NOT NULL DEFAULT 15,
    mfa_required_for           ENUM('all','staff_admin_only') NOT NULL DEFAULT 'all',
    updated_by                 INT UNSIGNED NULL,
    updated_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_policy_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_policy_singleton CHECK (policy_id = 1)
) ENGINE=InnoDB;

INSERT INTO security_policy (policy_id) VALUES (1);

CREATE TABLE audit_logs (
    log_id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NULL,
    action          ENUM('login','logout','create','update','delete','approve','reject','lock','unlock') NOT NULL,
    description     VARCHAR(500) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE genres (
    genre_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    genre_name      VARCHAR(100) NOT NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    UNIQUE KEY uk_genres_name (genre_name)
) ENGINE=InnoDB;

CREATE TABLE authors (
    author_id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name               VARCHAR(150) NOT NULL,
    bio                     TEXT NULL,
    verification_status     ENUM('verified','unconfirmed_self_declared','pending_review','unverified')
                             NOT NULL DEFAULT 'unverified',
    verification_confidence DECIMAL(5,2) NULL,
    verification_method     ENUM('exact','fuzzy','external','manual_staff') NULL,
    self_declared_note      VARCHAR(500) NULL,
    verified_by             INT UNSIGNED NULL,
    verified_at             DATETIME NULL,
    CONSTRAINT fk_authors_verified_by
        FOREIGN KEY (verified_by) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_authors_name (full_name)
) ENGINE=InnoDB;

CREATE TABLE books (
    book_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(255) NOT NULL,
    description     TEXT NULL,
    isbn            VARCHAR(20) NULL,
    genre_id        INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_books_genre
        FOREIGN KEY (genre_id) REFERENCES genres(genre_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    UNIQUE KEY uk_books_isbn (isbn),
    INDEX idx_books_title (title)
) ENGINE=InnoDB;

CREATE TABLE book_authors (
    book_id         INT UNSIGNED NOT NULL,
    author_id       INT UNSIGNED NOT NULL,
    PRIMARY KEY (book_id, author_id),
    CONSTRAINT fk_ba_book
        FOREIGN KEY (book_id) REFERENCES books(book_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ba_author
        FOREIGN KEY (author_id) REFERENCES authors(author_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE book_editions (
    edition_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id             INT UNSIGNED NOT NULL,
    format              ENUM('paperback','hardbound','ebook') NOT NULL,
    price               DECIMAL(10,2) UNSIGNED NOT NULL,
    stock_quantity      INT UNSIGNED NOT NULL DEFAULT 0,
    low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 5,
    cover_image_path    VARCHAR(255) NULL,
    CONSTRAINT fk_editions_book
        FOREIGN KEY (book_id) REFERENCES books(book_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uk_edition_book_format (book_id, format),
    INDEX idx_editions_stock (stock_quantity, low_stock_threshold)
) ENGINE=InnoDB;

CREATE TABLE carts (
    cart_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_carts_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uk_carts_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE cart_items (
    cart_item_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cart_id         INT UNSIGNED NOT NULL,
    edition_id      INT UNSIGNED NOT NULL,
    quantity        INT UNSIGNED NOT NULL DEFAULT 1,
    CONSTRAINT fk_cart_items_cart
        FOREIGN KEY (cart_id) REFERENCES carts(cart_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cart_items_edition
        FOREIGN KEY (edition_id) REFERENCES book_editions(edition_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uk_cart_edition (cart_id, edition_id)
) ENGINE=InnoDB;

CREATE TABLE orders (
    order_id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    order_number        VARCHAR(30) NOT NULL,
    shipping_name       VARCHAR(150) NOT NULL,
    shipping_mobile     VARCHAR(20)  NOT NULL,
    shipping_street     VARCHAR(255) NOT NULL,
    shipping_city       VARCHAR(100) NOT NULL,
    shipping_province   VARCHAR(100) NOT NULL,
    shipping_zip        VARCHAR(10)  NOT NULL,
    status              ENUM('pending','processing','shipped','delivered','cancelled')
                         NOT NULL DEFAULT 'pending',
    subtotal            DECIMAL(10,2) UNSIGNED NOT NULL,
    shipping_fee        DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0,
    tax_amount          DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0,
    total_amount        DECIMAL(10,2) UNSIGNED NOT NULL,
    placed_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    UNIQUE KEY uk_orders_number (order_number),
    INDEX idx_orders_user (user_id),
    INDEX idx_orders_status (status)
) ENGINE=InnoDB;

CREATE TABLE order_items (
    order_item_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED NOT NULL,
    edition_id      INT UNSIGNED NOT NULL,
    quantity        INT UNSIGNED NOT NULL,
    unit_price      DECIMAL(10,2) UNSIGNED NOT NULL,
    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id) REFERENCES orders(order_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_order_items_edition
        FOREIGN KEY (edition_id) REFERENCES book_editions(edition_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_order_items_order (order_id)
) ENGINE=InnoDB;

CREATE TABLE payments (
    payment_id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id                INT UNSIGNED NOT NULL,
    gateway                 ENUM('paypal','stripe','maya','gcash') NOT NULL,
    gateway_transaction_id  VARCHAR(100) NULL,
    amount                  DECIMAL(10,2) UNSIGNED NOT NULL,
    status                  ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    attempted_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at                 DATETIME NULL,
    CONSTRAINT fk_payments_order
        FOREIGN KEY (order_id) REFERENCES orders(order_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_payments_order (order_id)
) ENGINE=InnoDB;

CREATE TABLE reviews (
    review_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id         INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    rating          TINYINT UNSIGNED NOT NULL,
    comment         TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reviews_book
        FOREIGN KEY (book_id) REFERENCES books(book_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_reviews_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5),
    UNIQUE KEY uk_review_book_user (book_id, user_id)
) ENGINE=InnoDB;

CREATE TABLE daily_spotlight (
    spotlight_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    spotlight_date  DATE NOT NULL,
    book_id         INT UNSIGNED NOT NULL,
    author_id       INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_spotlight_book
        FOREIGN KEY (book_id) REFERENCES books(book_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_spotlight_author
        FOREIGN KEY (author_id) REFERENCES authors(author_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    UNIQUE KEY uk_spotlight_date (spotlight_date)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;