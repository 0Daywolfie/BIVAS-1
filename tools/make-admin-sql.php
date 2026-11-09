<?php
/**
 * Run this on YOUR OWN computer, never on the server. It needs no database and no config.
 * It prints one SQL INSERT (with a bcrypt hash, never the password) to paste into phpMyAdmin.
 *
 *   php make-admin-sql.php <estate_id> <phone> "<full name>"
 *   php make-admin-sql.php 1 08033333333 "Funmi Adebayo"
 *
 * The password is typed at a hidden prompt, so it never lands in your shell history.
 * It must be 10+ characters, the same rule as tools/create-admin.php.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

[$_, $estateId, $phoneRaw, $name] = array_pad($argv, 4, null);
if (!ctype_digit((string) $estateId) || !$phoneRaw || !$name) {
    fwrite(STDERR, "Usage: php make-admin-sql.php <estate_id> <phone> \"<full name>\"\n");
    exit(1);
}

$digits = preg_replace('/^(234|0)/', '', preg_replace('/\D+/', '', $phoneRaw));
if (!preg_match('/^[789]\d{9}$/', $digits)) { fwrite(STDERR, "Enter a valid Nigerian phone number\n"); exit(1); }
$phone = '+234' . $digits;

fwrite(STDERR, "Admin password (10+ characters, hidden): ");
@system('stty -echo 2>/dev/null');
$password = rtrim((string) fgets(STDIN), "\r\n");
@system('stty echo 2>/dev/null');
fwrite(STDERR, "\n");
if (strlen($password) < 10) { fwrite(STDERR, "Admin passwords must be 10+ characters\n"); exit(1); }

$q = static fn(string $s): string => "'" . str_replace(["\\", "'"], ["\\\\", "''"], $s) . "'";

echo "INSERT INTO estate_admins (estate_id, full_name, phone, password_hash) VALUES ("
   . (int) $estateId . ', ' . $q($name) . ', ' . $q($phone) . ', ' . $q(password_hash($password, PASSWORD_DEFAULT))
   . ");\n";
