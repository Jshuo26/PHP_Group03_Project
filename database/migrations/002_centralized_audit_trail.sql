-- Centralized activity trail for customer, staff, administrator, and system actions.
-- Apply once to an existing database before deploying the structured audit viewer.
ALTER TABLE audit_logs
    MODIFY COLUMN action VARCHAR(64) NOT NULL,
    ADD COLUMN actor_name VARCHAR(255) NULL AFTER user_id,
    ADD COLUMN module VARCHAR(50) NULL AFTER created_at,
    ADD COLUMN actor_role VARCHAR(30) NULL AFTER module,
    ADD COLUMN record_type VARCHAR(50) NULL AFTER actor_role,
    ADD COLUMN record_id BIGINT UNSIGNED NULL AFTER record_type,
    ADD COLUMN result VARCHAR(16) NULL AFTER record_id,
    ADD INDEX idx_audit_user_created (user_id, created_at),
    ADD INDEX idx_audit_module_action (module, action, created_at);
