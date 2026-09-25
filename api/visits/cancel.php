<?php
/** POST /api/visits/cancel.php  (resident token)  { "visit_id": 12 } */
require_once __DIR__ . '/../lib/bootstrap.php';
require_method('POST');
$resident = require_user('resident');
$visitId = int_field('visit_id');

$stmt = db()->prepare(
    "UPDATE visit_requests
     SET status = 'cancelled', access_code_hash = NULL
     WHERE visit_id = ? AND created_by_resident_id = ? AND status IN ('pending','approved')"
);
$stmt->execute([$visitId, $resident['resident_id']]);

if ($stmt->rowCount() === 0) fail(404, 'No active invite with that ID on your account');
json_out(['success' => true]);
