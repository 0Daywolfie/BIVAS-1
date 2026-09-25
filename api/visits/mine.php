<?php
/** GET /api/visits/mine.php  (resident token) -> the resident's 50 most recent invites */
require_once __DIR__ . '/../lib/bootstrap.php';
require_method('GET');
$resident = require_user('resident');

$stmt = db()->prepare(
    'SELECT visit_id, visitor_name, visitor_phone, purpose, valid_from, valid_to, status, created_at
     FROM visit_requests WHERE created_by_resident_id = ?
     ORDER BY created_at DESC, visit_id DESC LIMIT 50'
);
$stmt->execute([$resident['resident_id']]);

$visits = array_map(fn($v) => [
    'visit_id' => (int) $v['visit_id'],
    'visitor_name' => $v['visitor_name'],
    'visitor_phone' => $v['visitor_phone'],
    'purpose' => $v['purpose'],
    'valid_from' => iso($v['valid_from']),
    'valid_to' => iso($v['valid_to']),
    // Show expired even if the cleanup cron hasn't run yet
    'status' => ($v['status'] === 'approved' && iso($v['valid_to']) < utc_now()->format(DATE_ATOM))
        ? 'expired' : $v['status'],
], $stmt->fetchAll());

json_out(['success' => true, 'visits' => $visits]);
