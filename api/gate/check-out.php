<?php
/** POST /api/gate/check-out.php  (staff token)  { "entry_id": 7 } */
require_once __DIR__ . '/../lib/bootstrap.php';
require_method('POST');
$staff = require_user('staff');
$entryId = int_field('entry_id');

$stmt = db()->prepare(
    "UPDATE gate_entry_logs SET check_out_time = UTC_TIMESTAMP()
     WHERE entry_id = ? AND estate_id = ? AND decision = 'allowed' AND check_out_time IS NULL"
);
$stmt->execute([$entryId, $staff['estate_id']]);

if ($stmt->rowCount() === 0) fail(404, 'No open entry with that ID (already checked out, or never let in)');
json_out(['success' => true]);
