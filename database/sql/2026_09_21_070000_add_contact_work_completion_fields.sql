-- Contact Work Cycle v1
-- Run once after Caller Contact Follow-up Work System v1.
-- Adds completion/audit fields for non-call Contact actions.

ALTER TABLE contact_followups
    ADD COLUMN completed_at DATETIME NULL AFTER notes,
    ADD COLUMN completed_by_agent_id BIGINT UNSIGNED NULL AFTER completed_at,
    ADD COLUMN completion_outcome_key VARCHAR(80) NULL AFTER completed_by_agent_id,
    ADD COLUMN completion_notes VARCHAR(2000) NULL AFTER completion_outcome_key,
    ADD KEY contact_followups_completed_idx (completed_by_agent_id, completed_at);
