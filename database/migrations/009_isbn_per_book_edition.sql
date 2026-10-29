-- ISBNs identify a specific edition/binding, not the general work.
-- Apply once after migration 007 on an existing database.
ALTER TABLE book_editions
    ADD COLUMN isbn VARCHAR(20) NULL AFTER format,
    ADD UNIQUE KEY uk_book_editions_isbn (isbn);

-- Preserve an existing ISBN on books that currently have exactly one format.
-- Multi-format legacy records remain on books.isbn until staff confirms the mapping.
UPDATE book_editions e
JOIN books b ON b.book_id = e.book_id
   SET e.isbn = b.isbn
 WHERE e.isbn IS NULL
   AND b.isbn IS NOT NULL
   AND (SELECT COUNT(*) FROM book_editions e2 WHERE e2.book_id = e.book_id) = 1;
