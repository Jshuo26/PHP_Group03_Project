-- Bulk catalog import support and schema alignment for the current book workflow.
-- Apply once to an existing database that was created from the earlier database.sql.

ALTER TABLE authors
    MODIFY COLUMN verification_status ENUM('verified','unconfirmed_self_declared','pending_review','unverified','rejected')
        NOT NULL DEFAULT 'unverified';

ALTER TABLE books
    ADD COLUMN language VARCHAR(50) NOT NULL DEFAULT 'Filipino' AFTER isbn,
    ADD COLUMN publisher VARCHAR(150) NULL AFTER language,
    ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER publisher,
    ADD COLUMN review_status ENUM('approved','pending_review','rejected') NOT NULL DEFAULT 'pending_review' AFTER status,
    ADD COLUMN metadata_source_url VARCHAR(1000) NULL AFTER review_status;

ALTER TABLE book_editions
    MODIFY COLUMN cover_image_path VARCHAR(1000) NULL,
    ADD COLUMN cover_source_url VARCHAR(1000) NULL AFTER cover_image_path,
    ADD COLUMN cover_license_note VARCHAR(500) NULL AFTER cover_source_url;

CREATE TABLE author_aliases (
    alias_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_id       INT UNSIGNED NOT NULL,
    alias           VARCHAR(150) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_author_alias_author FOREIGN KEY (author_id) REFERENCES authors(author_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY uk_author_alias (author_id, alias),
    INDEX idx_author_alias_name (alias)
) ENGINE=InnoDB;

CREATE TABLE author_evidence (
    evidence_id     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_id       INT UNSIGNED NOT NULL,
    source_url      VARCHAR(500) NOT NULL,
    added_by        INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_author_evidence_author FOREIGN KEY (author_id) REFERENCES authors(author_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_author_evidence_user FOREIGN KEY (added_by) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_author_evidence_created (author_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE author_reviews (
    review_id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_id       INT UNSIGNED NOT NULL,
    book_id         INT UNSIGNED NULL,
    decision        ENUM('auto_verified','verified','rejected','needs_review','revoked','reopened') NOT NULL,
    notes           VARCHAR(2000) NULL,
    reviewer_id     INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_author_review_author FOREIGN KEY (author_id) REFERENCES authors(author_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_author_review_book FOREIGN KEY (book_id) REFERENCES books(book_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_author_review_user FOREIGN KEY (reviewer_id) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_author_review_history (author_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE book_import_batches (
    batch_id        CHAR(32) NOT NULL PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    status          ENUM('review','imported') NOT NULL DEFAULT 'review',
    rows_json       LONGTEXT NOT NULL,
    row_count       SMALLINT UNSIGNED NOT NULL,
    imported_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    result_json     TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    imported_at     DATETIME NULL,
    CONSTRAINT fk_book_import_user FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_book_import_user_created (user_id, created_at),
    INDEX idx_book_import_status (status, created_at)
) ENGINE=InnoDB;

-- Existing products are public only if their authors are already verified and
-- they have at least one active genre. Unverified books remain pending.
UPDATE books b
   SET b.review_status = CASE
       WHEN EXISTS (SELECT 1 FROM book_genres bg WHERE bg.book_id = b.book_id)
        AND EXISTS (SELECT 1 FROM book_authors ba WHERE ba.book_id = b.book_id)
        AND NOT EXISTS (SELECT 1 FROM book_authors ba JOIN authors a ON a.author_id = ba.author_id
                         WHERE ba.book_id = b.book_id AND a.verification_status <> 'verified')
       THEN 'approved' ELSE 'pending_review' END;
