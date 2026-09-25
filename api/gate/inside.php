<?php
/** GET /api/gate/inside.php  (staff token) -> visitors currently inside the estate */
require_once __DIR__ . '/../lib/bootstrap.php';
require_method('GET');
$staff = require_user('staff');

$stmt = db()->prepare(
    "SELECT e.entry_id, vi.full_name AS visitor_name, vi.phone AS visitor_phone,
            e.check_in_time, e.vehicle_plate, u.unit_code, u.block
     FROM gate_entry_logs e
     JOIN visitors vi ON vi.visitor_id = e.visitor_id
     LEFT JOIN visit_requests v ON v.visit_id = e.visit_id
     LEFT JOIN units u ON u.unit_id = v.unit_id
     WHERE e.estate_id = ? AND e.decision = 'allowed' AND e.check_out_time IS NULL
     ORDER BY e.check_in_time"
);
$stmt->execute([$staff['estate_id']]);

$inside = array_map(fn($r) => [
    'entry_id' => (int) $r['entry_id'],
    'visitor_name' => $r['visitor_name'],
    'visitor_phone' => $r['visitor_phone'],
    'check_in_time' => iso($r['check_in_time']),
    'vehicle_plate' => $r['vehicle_plate'],
    'destination' => $r['unit_code'] === null ? 'Walk-in'
        : trim(($r['block'] ? "Block {$r['block']}, " : '') . "Unit {$r['unit_code']}"),
], $stmt->fetchAll());

json_out(['success' => true, 'count' => count($inside), 'inside' => $inside]);
