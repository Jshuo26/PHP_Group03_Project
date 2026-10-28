-- Store the accepted TOTP time step so a code cannot be replayed.
-- Safe to re-run when the column already exists.
SET @mfa_last_used_step_sql = IF(
    (SELECT COUNT(*)
       FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'mfa_settings'
        AND column_name = 'last_used_step') = 0,
    'ALTER TABLE mfa_settings ADD COLUMN last_used_step BIGINT UNSIGNED NULL AFTER secret_key',
    'SELECT 1'
);
PREPARE mfa_last_used_step_stmt FROM @mfa_last_used_step_sql;
EXECUTE mfa_last_used_step_stmt;
DEALLOCATE PREPARE mfa_last_used_step_stmt;
