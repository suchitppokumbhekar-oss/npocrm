-- NPO CRM — Attendance Check-in Radius
-- No schema change. This only updates the existing active Head Office radius.
-- Current baseline value: 150m -> new value: 300m.
UPDATE office_locations
SET radius_meters = 300,
    updated_at = CURRENT_TIMESTAMP
WHERE id = 1
  AND is_active = 1;
