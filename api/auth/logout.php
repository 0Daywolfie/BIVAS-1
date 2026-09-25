<?php
/** POST /api/auth/logout.php  (Authorization: Bearer <token>) */
require_once __DIR__ . '/../lib/bootstrap.php';
require_method('POST');

$token = bearer_token() ?? fail(401, 'No session to end');
db()->prepare('UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP() WHERE token_hash = ? AND revoked_at IS NULL')
    ->execute([hash('sha256', $token)]);

json_out(['success' => true]);
