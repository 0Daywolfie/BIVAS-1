<?php
/**
 * POST /api/gate/verify.php   (staff token)   { "code": "482913" }
 * Looks up the code and shows the guard who is expected. Does NOT let
 * anyone in by itself; the guard confirms via check-in.php.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_method('POST');
$staff = require_user('staff');

// ---- Rate limit: stop a guard device from guessing codes ----
$window = (int) config('verify_window_minutes');
$stmt = db()->prepare(
    'SELECT COUNT(*) FROM verify_attempts
     WHERE staff_id = ? AND success = FALSE
       AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE'
);
$stmt->execute([$staff['staff_id'], $window]);
if ((int) $stmt->fetchColumn() >= config('verify_max_failures')) {
    fail(429, "Too many wrong codes. Wait {$window} minutes or call a supervisor.");
}

$code = str_field('code', true, 12);
$logAttempt = function (bool $success, ?int $visitId) use ($staff): void {
    db()->prepare(
        'INSERT INTO verify_attempts (staff_id, estate_id, success, visit_id, attempted_at)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP())'
    )->execute([$staff['staff_id'], $staff['estate_id'], (int) $success, $visitId]);
};

if (!preg_match('/^\d{6}$/', $code)) {
    $logAttempt(false, null);
    fail(422, 'Codes are 6 digits');
}

$stmt = db()->prepare(
    "SELECT v.visit_id, v.visitor_name, v.visitor_phone, v.purpose, v.valid_from, v.valid_to,
            u.unit_code, u.block, r.full_name AS host_name, r.phone AS host_phone
     FROM visit_requests v
     JOIN units u ON u.unit_id = v.unit_id
     JOIN residents r ON r.resident_id = v.created_by_resident_id
     WHERE v.estate_id = ? AND v.access_code_hash = ? AND v.status = 'approved'"
);
$stmt->execute([$staff['estate_id'], hash_access_code($code)]);
$visit = $stmt->fetch();

if (!$visit) {
    $logAttempt(false, null);
    fail(404, 'Invalid code. Do not admit; ask the visitor to call their host.');
}

$now = sql_dt(utc_now());
if ($now < $visit['valid_from']) {
    $logAttempt(false, (int) $visit['visit_id']);
    fail(409, 'This code is not valid yet', ['valid_from' => iso($visit['valid_from'])]);
}
if ($now > $visit['valid_to']) {
    db()->prepare("UPDATE visit_requests SET status = 'expired', access_code_hash = NULL WHERE visit_id = ?")
        ->execute([$visit['visit_id']]);
    $logAttempt(false, (int) $visit['visit_id']);
    fail(410, 'This code has expired');
}

$logAttempt(true, (int) $visit['visit_id']);
json_out([
    'success' => true,
    'visit' => [
        'visit_id' => (int) $visit['visit_id'],
        'visitor_name' => $visit['visitor_name'],
        'visitor_phone' => $visit['visitor_phone'],
        'purpose' => $visit['purpose'],
        'destination' => unit_label($visit['block'], $visit['unit_code']),
        'host_name' => $visit['host_name'],
        'host_phone' => $visit['host_phone'],
        'valid_to' => iso($visit['valid_to']),
    ],
]);
