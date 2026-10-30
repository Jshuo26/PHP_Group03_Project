-- Shared staff picks for the customer Featured page.
-- Safe to apply when the table already exists; featured.php expects these columns.
CREATE TABLE IF NOT EXISTS featured_books (
    book_id INT UNSIGNED NOT NULL PRIMARY KEY,
    display_order INT UNSIGNED NOT NULL DEFAULT 0,
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_featured_books_book
        FOREIGN KEY (book_id) REFERENCES books(book_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_featured_books_order (display_order, added_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
