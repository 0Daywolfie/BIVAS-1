<?php
/** GET /api/auth/me.php  (any token) -> who is signed in, and where */
require_once __DIR__ . '/../lib/bootstrap.php';
require_method('GET');
$user = require_user(null);

$out = [
    'success' => true,
    'user_type' => $user['user_type'],
    'name' => $user['full_name'],
    'estate_name' => $user['estate_name'],
];
if ($user['user_type'] === 'resident') {
    $out['unit'] = unit_label($user['block'], $user['unit_code']);
} elseif ($user['user_type'] === 'staff') {
    $out['role'] = $user['role'];
}
json_out($out);
