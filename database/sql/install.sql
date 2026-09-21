-- FIX32D: permit the tested Site Team outcome to advance Contacted -> Visit Done.
INSERT INTO lead_status_transitions (from_status_id, to_status_id, created_at, updated_at)
SELECT fs.id, ts.id, NOW(), NOW()
FROM lead_statuses fs
JOIN lead_statuses ts ON ts.`key` = 'visit_done'
WHERE fs.`key` = 'contacted'
  AND NOT EXISTS (
    SELECT 1 FROM lead_status_transitions x
    WHERE x.from_status_id = fs.id AND x.to_status_id = ts.id
  );
