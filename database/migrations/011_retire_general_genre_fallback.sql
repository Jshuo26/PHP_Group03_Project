-- Hide the old generic placeholder from active genre choices without deleting
-- existing book_genres references. Staff can reclassify any affected titles.
UPDATE genres
SET status = 'inactive'
WHERE genre_name = 'Other / General';
