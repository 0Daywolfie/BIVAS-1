<?php
/**
 * POST /api/auth/login.php
 * Resident: { "as": "resident", "phone": "08031234567", "password": "..." }
 * Guard:    { "as": "staff",    "phone": "08031234567", "pin": "123456" }
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_method('POST');

$as = str_field('as');
$phone = normalize_phone(str_field('phone'));

if ($as === 'resident') {
    $secret = str_field('password', true, 200);
    $stmt = db()->prepare(
        'SELECT resident_id AS id, full_name, password_hash AS hash
         FROM residents WHERE phone = ? AND is_active = TRUE'
    );
} elseif ($as === 'staff') {
    $secret = str_field('pin', true, 12);
    $stmt = db()->prepare(
        'SELECT staff_id AS id, full_name, pin_hash AS hash
         FROM security_staff WHERE phone = ? AND is_active = TRUE'
    );
} else {
    fail(422, 'as must be "resident" or "staff"');
}

$stmt->execute([$phone]);
$user = $stmt->fetch();

// Always run password_verify, even for unknown phones, so response timing
// doesn't reveal which phone numbers are registered.
$dummy = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
$ok = password_verify($secret, $user['hash'] ?? $dummy) && !empty($user['hash']);

if (!$ok) fail(401, 'Wrong phone number or password');

json_out(['success' => true, 'user_type' => $as, 'name' => $user['full_name']]
    + issue_token($as, (int) $user['id']));
