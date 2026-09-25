<?php
/**
 * GET /api/admin/audit.php  (admin token)  ?before=123  -> admin actions, newest first
 * Read-only on purpose: there is no endpoint to edit or delete audit entries.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_method('GET');
$admin = require_user('admin');

$where = ['l.estate_id = ?'];
$params = [$admin['estate_id']];
if (isset($_GET['before'])) {
    $before = filter_var($_GET['before'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($before === false) fail(422, 'before must be a positive integer');
    $where[] = 'l.audit_id < ?'; $params[] = $before;
}

$limit = 50;
$stmt = db()->prepare(
    'SELECT l.audit_id, l.action, l.target_type, l.target_id, l.summary, l.ip_address, l.created_at,
            a.full_name AS admin_name
     FROM admin_audit_log l JOIN estate_admins a ON a.admin_id = l.admin_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY l.audit_id DESC LIMIT ' . ($limit + 1)
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$more = count($rows) > $limit;
$rows = array_slice($rows, 0, $limit);

json_out([
    'success' => true,
    'entries' => array_map(fn($r) => [
        'audit_id' => (int) $r['audit_id'],
        'action' => $r['action'],
        'target_type' => $r['target_type'],
        'target_id' => $r['target_id'] === null ? null : (int) $r['target_id'],
        'summary' => $r['summary'],
        'admin_name' => $r['admin_name'],
        'ip_address' => $r['ip_address'],
        'created_at' => iso($r['created_at']),
    ], $rows),
    'next_before' => $more ? (int) end($rows)['audit_id'] : null,
]);
