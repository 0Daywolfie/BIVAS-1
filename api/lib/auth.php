<?php
declare(strict_types=1);

function issue_token(string $userType, int $userId): array {
    $token = bin2hex(random_bytes(32));
    $ttl = $userType === 'staff' ? config('staff_token_ttl_hours') : config('resident_token_ttl_hours');
    $expires = utc_now()->modify("+{$ttl} hours");

    db()->prepare(
        'INSERT INTO api_tokens (token_hash, user_type, user_id, expires_at) VALUES (?, ?, ?, ?)'
    )->execute([hash('sha256', $token), $userType, $userId, sql_dt($expires)]);

    return ['token' => $token, 'expires_at' => $expires->format(DATE_ATOM)];
}

function bearer_token(): ?string {
    // cPanel/Apache often strips Authorization; .htaccess copies it into HTTP_AUTHORIZATION.
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $header = getallheaders()['Authorization'] ?? '';
    }
    return preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($header), $m) ? strtolower($m[1]) : null;
}

/**
 * Returns the logged-in user or stops with 401/403.
 * Pass null to accept either account type.
 * Resident shape: user_type, resident_id, unit_id, estate_id, full_name, unit_code, block, estate_name
 * Staff shape:    user_type, staff_id, estate_id, full_name, role, estate_name
 */
function require_user(?string $userType): array {
    $token = bearer_token() ?? fail(401, 'Log in first');

    $stmt = db()->prepare(
        'SELECT user_type, user_id FROM api_tokens
         WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()'
    );
    $stmt->execute([hash('sha256', $token)]);
    $session = $stmt->fetch() ?: fail(401, 'Session expired, log in again');

    if ($userType !== null && $session['user_type'] !== $userType) fail(403, 'Not allowed for your account type');

    if ($session['user_type'] === 'resident') {
        $stmt = db()->prepare(
            "SELECT 'resident' AS user_type, r.resident_id, r.unit_id, u.estate_id, r.full_name,
                    u.unit_code, u.block, e.name AS estate_name
             FROM residents r
             JOIN units u ON u.unit_id = r.unit_id
             JOIN estates e ON e.estate_id = u.estate_id
             WHERE r.resident_id = ? AND r.is_active = TRUE"
        );
    } else {
        $stmt = db()->prepare(
            "SELECT 'staff' AS user_type, s.staff_id, s.estate_id, s.full_name, s.role,
                    e.name AS estate_name
             FROM security_staff s JOIN estates e ON e.estate_id = s.estate_id
             WHERE s.staff_id = ? AND s.is_active = TRUE"
        );
    }
    $stmt->execute([$session['user_id']]);
    return $stmt->fetch() ?: fail(403, 'Account is deactivated');
}

function hash_access_code(string $code): string {
    return hash_hmac('sha256', $code, config('code_pepper'));
}
