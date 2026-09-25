-- Demo data for local testing / portfolio demos. Fake people, fake estate.
-- After running, set credentials with tools/set-credential.php.
INSERT INTO estates (name, address) VALUES ('Palmview Estate', 'Lekki Phase 1, Lagos');
INSERT INTO units (estate_id, unit_code, block) VALUES (1, 'B12', 'B'), (1, 'C4', 'C');
INSERT INTO residents (unit_id, full_name, phone, email, is_primary)
  VALUES (1, 'Adaeze Okafor', '+2348031111111', 'adaeze@example.com', TRUE);
INSERT INTO security_staff (estate_id, full_name, phone, role, shift)
  VALUES (1, 'Musa Ibrahim', '+2348032222222', 'guard', 'Day');
