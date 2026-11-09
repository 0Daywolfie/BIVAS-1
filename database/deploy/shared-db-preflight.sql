-- BIVAS-1 pre-flight. Run in phpMyAdmin (SQL tab) on the shared database BEFORE importing shared-db-schema.sql.
-- Expected result: ZERO rows.
-- Any row returned = a table with that name already exists in this database. Stop and
-- check which one(s): it is either an earlier partial BIVAS install or a table that
-- belongs to another app. Do not import the schema until the list is empty.
SELECT table_name
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
    'estates', 'units', 'residents', 'security_staff', 'visitors',
    'visit_requests', 'gate_entry_logs', 'bivas_vehicles', 'bivas_incident_reports',
    'api_tokens', 'verify_attempts', 'estate_admins', 'admin_audit_log'
  );
