-- Restore the generic genre as an active simulation fallback for bulk import.
-- Specific provider genre matches replace this value during metadata review.
INSERT INTO genres (genre_name, status)
VALUES ('Other / General', 'active')
ON DUPLICATE KEY UPDATE status = 'active';
