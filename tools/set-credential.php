<?php
/**
 * CLI only. Sets a resident password or a guard PIN.
 *   php tools/set-credential.php resident 08031234567 'S3cure-pass'
 *   php tools/set-credential.php staff    08039876543 482913
 * Keep /tools OUTSIDE public_html when you deploy.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/lib/http.php';
require_once __DIR__ . '/../api/lib/db.php';

[$_, $type, $phoneRaw, $secret] = array_pad($argv, 4, null);
if (!in_array($type, ['resident', 'staff'], true) || !$phoneRaw || !$secret) {
    fwrite(STDERR, "Usage: php set-credential.php resident|staff <phone> <password-or-pin>\n");
    exit(1);
}
if ($type === 'resident' && strlen($secret) < 8) { fwrite(STDERR, "Password must be 8+ characters\n"); exit(1); }
if ($type === 'staff' && !preg_match('/^\d{6}$/', $secret)) { fwrite(STDERR, "PIN must be exactly 6 digits\n"); exit(1); }

$digits = preg_replace('/\D+/', '', $phoneRaw);
$digits = preg_replace('/^(234|0)/', '', $digits);
$phone = '+234' . $digits;

$sql = $type === 'resident'
    ? 'UPDATE residents SET password_hash = ? WHERE phone = ?'
    : 'UPDATE security_staff SET pin_hash = ? WHERE phone = ?';
$stmt = db()->prepare($sql);
$stmt->execute([password_hash($secret, PASSWORD_DEFAULT), $phone]);

echo $stmt->rowCount() ? "Credential set for $type $phone\n" : "No $type found with phone $phone\n";
exit($stmt->rowCount() ? 0 : 1);
