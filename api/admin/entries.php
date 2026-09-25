<?php
/**
 * GET  /api/admin/entries.php  (admin token)  -> gate log, newest first
 *   ?from=2026-09-01&to=2026-09-25   Lagos calendar dates, both inclusive (optional)
 *   ?q=tunde                         visitor name, visitor phone or vehicle plate (optional)
 *   ?decision=allowed|denied         (optional)
 *   ?inside=1                        only people still inside (optional)
 *   ?before=123                      paging: entries older than this entry_id (optional)
 *
 * POST /api/admin/entries.php  { "action": "check_out", "entry_id": 7 }
 *   Checks someone out when the guard forgot to. Logged in the audit trail.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
$admin = require_user('admin');
$estate = $admin['estate_id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (str_field('action') !== 'check_out') fail(422, 'action must be "check_out"');
    $entryId = int_field('entry_id');

    $stmt = db()->prepare(
        "SELECT v.full_name FROM gate_entry_logs g JOIN visitors v ON v.visitor_id = g.visitor_id
         WHERE g.entry_id = ? AND g.estate_id = ? AND g.decision = 'allowed' AND g.check_out_time IS NULL"
    );
    $stmt->execute([$entryId, $estate]);
    $name = $stmt->fetchColumn();
    if ($name === false) fail(404, 'Nobody is inside under that entry (already checked out, or never let in)');

    db()->prepare('UPDATE gate_entry_logs SET check_out_time = UTC_TIMESTAMP() WHERE entry_id = ?')
        ->execute([$entryId]);
    audit($admin, 'entry.check_out', 'entry', $entryId, "Checked out {$name} manually");
    json_out(['success' => true]);
}

require_method('GET');
$where = ['g.estate_id = ?'];
$params = [$estate];

if ($from = query_text('from', 10)) { $where[] = 'g.check_in_time >= ?'; $params[] = lagos_day_start_utc($from); }
if ($to = query_text('to', 10)) {
    $where[] = 'g.check_in_time < ?';
    $params[] = sql_dt((new DateTimeImmutable(lagos_day_start_utc($to), new DateTimeZone('UTC')))->modify('+1 day'));
}
if ($q = query_text('q')) {
    $where[] = '(v.full_name LIKE ? OR v.phone LIKE ? OR g.vehicle_plate LIKE ?)';
    array_push($params, like_pattern($q), like_pattern($q), like_pattern(strtoupper(preg_replace('/\s+/', '', $q))));
}
if ($decision = query_text('decision', 10)) {
    if (!in_array($decision, ['allowed', 'denied'], true)) fail(422, 'decision must be allowed or denied');
    $where[] = 'g.decision = ?'; $params[] = $decision;
}
if (($_GET['inside'] ?? '') === '1') $where[] = "g.decision = 'allowed' AND g.check_out_time IS NULL";
if (isset($_GET['before'])) {
    $before = filter_var($_GET['before'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($before === false) fail(422, 'before must be a positive integer');
    $where[] = 'g.entry_id < ?'; $params[] = $before;
}

$limit = 50;
$stmt = db()->prepare(
    "SELECT g.entry_id, g.decision, g.check_in_time, g.check_out_time, g.vehicle_plate, g.entry_method, g.notes,
            v.full_name AS visitor_name, v.phone AS visitor_phone,
            vr.purpose, u.unit_code, u.block, r.full_name AS host_name,
            s.full_name AS guard_name
     FROM gate_entry_logs g
     JOIN visitors v ON v.visitor_id = g.visitor_id
     JOIN security_staff s ON s.staff_id = g.staff_id
     LEFT JOIN visit_requests vr ON vr.visit_id = g.visit_id
     LEFT JOIN units u ON u.unit_id = vr.unit_id
     LEFT JOIN residents r ON r.resident_id = vr.created_by_resident_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY g.entry_id DESC LIMIT " . ($limit + 1)
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$more = count($rows) > $limit;
$rows = array_slice($rows, 0, $limit);

json_out([
    'success' => true,
    'entries' => array_map(fn($r) => [
        'entry_id' => (int) $r['entry_id'],
        'decision' => $r['decision'],
        'visitor_name' => $r['visitor_name'],
        'visitor_phone' => $r['visitor_phone'],
        'purpose' => $r['purpose'],
        'destination' => $r['unit_code'] === null ? 'Walk-in' : unit_label($r['block'], $r['unit_code']),
        'host_name' => $r['host_name'],
        'guard_name' => $r['guard_name'],
        'vehicle_plate' => $r['vehicle_plate'],
        'entry_method' => $r['entry_method'],
        'notes' => $r['notes'],
        'check_in_time' => iso($r['check_in_time']),
        'check_out_time' => iso($r['check_out_time']),
    ], $rows),
    'next_before' => $more ? (int) end($rows)['entry_id'] : null,
]);
