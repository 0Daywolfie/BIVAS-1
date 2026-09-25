<?php
/**
 * POST /api/auth/login.php
 * Resident: { "as": "resident", "phone": "08031234567", "password": "..." }
 * Guard:    { "as": "staff",    "phone": "08031234567", "pin": "123456" }
 * Admin:    { "as": "admin",    "phone": "08031234567", "password": "..." }
 * Admin accounts lock for 15 minutes after 5 wrong passwords.
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
} elseif ($as === 'admin') {
    $secret = str_field('password', true, 200);
    $stmt = db()->prepare(
        'SELECT admin_id AS id, full_name, password_hash AS hash, locked_until
         FROM estate_admins WHERE phone = ? AND is_active = TRUE'
    );
} else {
    fail(422, 'as must be "resident", "staff" or "admin"');
}

$stmt->execute([$phone]);
$user = $stmt->fetch();

// Always run password_verify, even for unknown phones, so response timing
// doesn't reveal which phone numbers are registered.
$dummy = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
$ok = password_verify($secret, $user['hash'] ?? $dummy) && !empty($user['hash']);

if ($as === 'admin' && $user && $user['locked_until'] !== null && $user['locked_until'] > sql_dt(utc_now())) {
    fail(429, 'Too many wrong passwords. This account is locked for 15 minutes.');
}

if (!$ok) {
    if ($as === 'admin' && $user) {
        // 5th consecutive failure locks the account for 15 minutes and resets the counter
        db()->prepare(
            'UPDATE estate_admins
             SET locked_until = IF(failed_logins + 1 >= 5, UTC_TIMESTAMP() + INTERVAL 15 MINUTE, locked_until),
                 failed_logins = IF(failed_logins + 1 >= 5, 0, failed_logins + 1)
             WHERE admin_id = ?'
        )->execute([$user['id']]);
    }
    fail(401, 'Wrong phone number or password');
}

if ($as === 'admin') {
    db()->prepare(
        'UPDATE estate_admins SET failed_logins = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP()
         WHERE admin_id = ?'
    )->execute([$user['id']]);
}

json_out(['success' => true, 'user_type' => $as, 'name' => $user['full_name']]
    + issue_token($as, (int) $user['id']));
