<?php
/**
 * GET /api/admin/overview.php  (admin token)
 * The "what's happening at my gate" screen: today's numbers, who's inside,
 * locked-out guards and the latest gate activity.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_method('GET');
$admin = require_user('admin');
$estate = $admin['estate_id'];

$todayStart = lagos_day_start_utc((new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos')))->format('Y-m-d'));
$count = function (string $sql, array $params): int {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
};

$stats = [
    'inside_now' => $count(
        "SELECT COUNT(*) FROM gate_entry_logs
         WHERE estate_id = ? AND decision = 'allowed' AND check_out_time IS NULL", [$estate]),
    'let_in_today' => $count(
        "SELECT COUNT(*) FROM gate_entry_logs
         WHERE estate_id = ? AND decision = 'allowed' AND check_in_time >= ?", [$estate, $todayStart]),
    'turned_away_today' => $count(
        "SELECT COUNT(*) FROM gate_entry_logs
         WHERE estate_id = ? AND decision = 'denied' AND check_in_time >= ?", [$estate, $todayStart]),
    'wrong_codes_today' => $count(
        'SELECT COUNT(*) FROM verify_attempts
         WHERE estate_id = ? AND success = FALSE AND attempted_at >= ?', [$estate, $todayStart]),
    'active_invites' => $count(
        "SELECT COUNT(*) FROM visit_requests
         WHERE estate_id = ? AND status IN ('pending','approved') AND valid_to > UTC_TIMESTAMP()", [$estate]),
];

// Guards currently locked out for too many wrong codes
$locked = [];
$staff = db()->prepare(
    'SELECT staff_id, full_name, lockout_cleared_at FROM security_staff WHERE estate_id = ? AND is_active = TRUE'
);
$staff->execute([$estate]);
$fails = db()->prepare(
    'SELECT COUNT(*) FROM verify_attempts WHERE staff_id = ? AND success = FALSE AND attempted_at > ?'
);
foreach ($staff->fetchAll() as $s) {
    $fails->execute([$s['staff_id'], lockout_window_start($s['lockout_cleared_at'])]);
    if ((int) $fails->fetchColumn() >= config('verify_max_failures')) {
        $locked[] = ['staff_id' => (int) $s['staff_id'], 'full_name' => $s['full_name']];
    }
}

$recent = db()->prepare(
    "SELECT g.entry_id, g.decision, g.check_in_time, g.check_out_time, g.notes,
            v.full_name AS visitor_name, u.unit_code, u.block, s.full_name AS guard_name
     FROM gate_entry_logs g
     JOIN visitors v ON v.visitor_id = g.visitor_id
     JOIN security_staff s ON s.staff_id = g.staff_id
     LEFT JOIN visit_requests vr ON vr.visit_id = g.visit_id
     LEFT JOIN units u ON u.unit_id = vr.unit_id
     WHERE g.estate_id = ?
     ORDER BY g.check_in_time DESC, g.entry_id DESC LIMIT 10"
);
$recent->execute([$estate]);

json_out([
    'success' => true,
    'estate_name' => $admin['estate_name'],
    'stats' => $stats,
    'locked_guards' => $locked,
    'recent' => array_map(fn($r) => [
        'entry_id' => (int) $r['entry_id'],
        'decision' => $r['decision'],
        'visitor_name' => $r['visitor_name'],
        'destination' => $r['unit_code'] === null ? 'Walk-in' : unit_label($r['block'], $r['unit_code']),
        'guard_name' => $r['guard_name'],
        'check_in_time' => iso($r['check_in_time']),
        'check_out_time' => iso($r['check_out_time']),
        'notes' => $r['notes'],
    ], $recent->fetchAll()),
]);
