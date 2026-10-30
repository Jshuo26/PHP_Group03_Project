-- Encrypted address values are larger than their plaintext equivalents.
-- Run once on databases created before these column sizes were expanded.
ALTER TABLE addresses
    MODIFY COLUMN label VARCHAR(255) NULL,
    ADD COLUMN recipient_name VARCHAR(800) NOT NULL DEFAULT '' AFTER label,
    ADD COLUMN mobile_number VARCHAR(120) NOT NULL DEFAULT '' AFTER recipient_name,
    MODIFY COLUMN street VARCHAR(1200) NOT NULL,
    MODIFY COLUMN city VARCHAR(500) NOT NULL,
    MODIFY COLUMN province VARCHAR(500) NOT NULL,
    MODIFY COLUMN zip_code VARCHAR(100) NOT NULL;
