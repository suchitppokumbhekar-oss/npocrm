-- Contact Caller Work System v1
-- Run once in production if migrations are not executed through Artisan.
-- This creates Contact-only follow-ups and incomplete-call markers.

CREATE TABLE IF NOT EXISTS contact_followups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NULL,
    scheduled_for DATETIME NULL,
    action_type VARCHAR(80) NOT NULL DEFAULT 'followup_call',
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    auto_created TINYINT(1) NOT NULL DEFAULT 1,
    source_call_id BIGINT UNSIGNED NULL,
    notes VARCHAR(2000) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY contact_followups_work_idx (agent_id, status, scheduled_for),
    KEY contact_followups_contact_idx (contact_id, status),
    CONSTRAINT contact_followups_contact_fk FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_call_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    call_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY contact_call_sessions_work_idx (agent_id, completed_at, started_at),
    KEY contact_call_sessions_contact_idx (contact_id, completed_at),
    CONSTRAINT contact_call_sessions_contact_fk FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
