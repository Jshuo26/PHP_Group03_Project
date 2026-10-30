-- Separate curated sections on the customer Featured page.
-- Apply once after migration 014_featured_books_curation.sql.
ALTER TABLE featured_books
    DROP PRIMARY KEY,
    ADD COLUMN shelf_key VARCHAR(30) NOT NULL DEFAULT 'staff_picks' FIRST,
    ADD PRIMARY KEY (shelf_key, book_id),
    ADD INDEX idx_featured_shelf_order (shelf_key, display_order, added_at);
