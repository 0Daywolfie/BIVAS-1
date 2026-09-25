<?php
/**
 * CLI only. Creates the first estate admin (after that, admins can be added from the database or a future screen).
 *   php tools/create-admin.php <estate_id> <phone> "<full name>" '<password>'
 *   php tools/create-admin.php 1 08033333333 "Funmi Adebayo" 'Str0ng-admin-pass'
 * Passwords must be 10+ characters. Keep /tools OUTSIDE public_html when you deploy.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/lib/http.php';
require_once __DIR__ . '/../api/lib/db.php';

[$_, $estateId, $phoneRaw, $name, $password] = array_pad($argv, 5, null);
if (!ctype_digit((string) $estateId) || !$phoneRaw || !$name || !$password) {
    fwrite(STDERR, "Usage: php create-admin.php <estate_id> <phone> \"<full name>\" '<password>'\n");
    exit(1);
}
if (strlen($password) < 10) { fwrite(STDERR, "Admin passwords must be 10+ characters\n"); exit(1); }

$digits = preg_replace('/^(234|0)/', '', preg_replace('/\D+/', '', $phoneRaw));
if (!preg_match('/^[789]\d{9}$/', $digits)) { fwrite(STDERR, "Enter a valid Nigerian phone number\n"); exit(1); }
$phone = '+234' . $digits;

$estate = db()->prepare('SELECT name FROM estates WHERE estate_id = ?');
$estate->execute([(int) $estateId]);
$estateName = $estate->fetchColumn();
if ($estateName === false) { fwrite(STDERR, "No estate with ID $estateId\n"); exit(1); }

try {
    db()->prepare('INSERT INTO estate_admins (estate_id, full_name, phone, password_hash) VALUES (?, ?, ?, ?)')
        ->execute([(int) $estateId, $name, $phone, password_hash($password, PASSWORD_DEFAULT)]);
} catch (PDOException $e) {
    if (is_duplicate_key($e)) { fwrite(STDERR, "An admin with phone $phone already exists\n"); exit(1); }
    throw $e;
}
echo "Admin created: $name ($phone) for $estateName\n";
