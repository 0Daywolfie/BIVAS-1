<?php
/**
 * GET  /api/admin/invites.php  (admin token)
 *   ?status=active (default) | used | cancelled | expired | all
 *   ?q=tunde   visitor name/phone or host name (optional)
 * Codes are never returned: only their hashes are stored, so even an admin can't see one.
 *
 * POST /api/admin/invites.php  { "action": "cancel", "visit_id": 12 }
 *   Kills a suspicious invite's code immediately. Logged in the audit trail.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
$admin = require_user('admin');
$estate = $admin['estate_id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (str_field('action') !== 'cancel') fail(422, 'action must be "cancel"');
    $visitId = int_field('visit_id');

    $stmt = db()->prepare(
        "SELECT v.visitor_name, r.full_name AS host_name FROM visit_requests v
         JOIN residents r ON r.resident_id = v.created_by_resident_id
         WHERE v.visit_id = ? AND v.estate_id = ? AND v.status IN ('pending','approved')"
    );
    $stmt->execute([$visitId, $estate]);
    $invite = $stmt->fetch() ?: fail(404, 'No active invite with that ID in your estate');

    db()->prepare("UPDATE visit_requests SET status = 'cancelled', access_code_hash = NULL WHERE visit_id = ?")
        ->execute([$visitId]);
    audit($admin, 'invite.cancel', 'invite', $visitId,
        "Cancelled {$invite['host_name']}'s invite for {$invite['visitor_name']}");
    json_out(['success' => true]);
}

require_method('GET');
$where = ['v.estate_id = ?'];
$params = [$estate];

$status = query_text('status', 12) ?? 'active';
switch ($status) {
    case 'active':    $where[] = "v.status IN ('pending','approved') AND v.valid_to > UTC_TIMESTAMP()"; break;
    case 'expired':   $where[] = "(v.status = 'expired' OR (v.status IN ('pending','approved') AND v.valid_to <= UTC_TIMESTAMP()))"; break;
    case 'used': case 'cancelled': $where[] = 'v.status = ?'; $params[] = $status; break;
    case 'all': break;
    default: fail(422, 'status must be active, used, cancelled, expired or all');
}
if ($q = query_text('q')) {
    $where[] = '(v.visitor_name LIKE ? OR v.visitor_phone LIKE ? OR r.full_name LIKE ?)';
    array_push($params, like_pattern($q), like_pattern($q), like_pattern($q));
}

$stmt = db()->prepare(
    "SELECT v.visit_id, v.visitor_name, v.visitor_phone, v.purpose, v.valid_from, v.valid_to, v.status, v.created_at,
            u.unit_code, u.block, r.full_name AS host_name
     FROM visit_requests v
     JOIN units u ON u.unit_id = v.unit_id
     JOIN residents r ON r.resident_id = v.created_by_resident_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY v.created_at DESC, v.visit_id DESC LIMIT 100"
);
$stmt->execute($params);
$now = utc_now()->format(DATE_ATOM);

json_out([
    'success' => true,
    'invites' => array_map(fn($v) => [
        'visit_id' => (int) $v['visit_id'],
        'visitor_name' => $v['visitor_name'],
        'visitor_phone' => $v['visitor_phone'],
        'purpose' => $v['purpose'],
        'host_name' => $v['host_name'],
        'destination' => unit_label($v['block'], $v['unit_code']),
        'valid_from' => iso($v['valid_from']),
        'valid_to' => iso($v['valid_to']),
        'status' => (in_array($v['status'], ['pending', 'approved'], true) && iso($v['valid_to']) < $now) ? 'expired' : $v['status'],
        'created_at' => iso($v['created_at']),
    ], $stmt->fetchAll()),
]);
