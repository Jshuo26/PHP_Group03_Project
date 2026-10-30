-- Keep the bulk-import placeholder available on databases that ran migration
-- 011, which retired the generic genre before migration 012 restored it.
-- Safe to apply more than once.
INSERT INTO genres (genre_name, status)
VALUES ('Other / General', 'active')
ON DUPLICATE KEY UPDATE status = 'active';
