-- Roll back only the Contacted -> Visit Done transition added by FIX32D.
DELETE x
FROM lead_status_transitions x
JOIN lead_statuses fs ON fs.id = x.from_status_id AND fs.`key` = 'contacted'
JOIN lead_statuses ts ON ts.id = x.to_status_id AND ts.`key` = 'visit_done';
