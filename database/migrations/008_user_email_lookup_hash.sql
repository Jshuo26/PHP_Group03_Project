-- Required by registration and login for normalized email lookup.
ALTER TABLE users
    ADD COLUMN email_lookup_hash CHAR(64) NULL AFTER email,
    ADD UNIQUE KEY uk_users_email_lookup_hash (email_lookup_hash);
