<?php
/**
 * POST /api/gate/check-in.php   (staff token)
 * {
 *   "visit_id": 12,
 *   "decision": "allowed" | "denied",
 *   "entry_method": "OTP" | "QR" | "Manual",   (optional, default OTP)
 *   "vehicle_plate": "LND-123-AB",              (optional)
 *   "notes": "Came with 2 others"               (optional; required if denied)
 * }
 * "allowed" consumes the code (single use). "denied" logs the refusal and
 * leaves the invite active so the host can sort it out.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_method('POST');
$staff = require_user('staff');

$visitId = int_field('visit_id');
$decision = str_field('decision');
if (!in_array($decision, ['allowed', 'denied'], true)) fail(422, 'decision must be "allowed" or "denied"');
$method = str_field('entry_method', false) ?? 'OTP';
if (!in_array($method, ['OTP', 'QR', 'Manual'], true)) fail(422, 'entry_method must be OTP, QR or Manual');
$plate = str_field('vehicle_plate', false, 20);
$plate = $plate === null ? null : strtoupper(preg_replace('/\s+/', '', $plate));
$notes = str_field('notes', $decision === 'denied', 500);

$pdo = db();
$pdo->beginTransaction();
try {
    // Lock the row so two guards can't admit the same code at the same moment.
    $stmt = $pdo->prepare(
        "SELECT visit_id, visitor_id, visitor_name, visitor_phone, valid_from, valid_to
         FROM visit_requests
         WHERE visit_id = ? AND estate_id = ? AND status = 'approved'
         FOR UPDATE"
    );
    $stmt->execute([$visitId, $staff['estate_id']]);
    $visit = $stmt->fetch();

    if (!$visit) {
        $pdo->rollBack();
        fail(409, 'This invite is no longer active (used, cancelled or expired). Verify the code again.');
    }
    $now = sql_dt(utc_now());
    if ($now < $visit['valid_from'] || $now > $visit['valid_to']) {
        $pdo->rollBack();
        fail(409, 'This invite is outside its valid time window');
    }

    // Gate logs require a visitor record: reuse by phone or create one.
    $visitorId = $visit['visitor_id'];
    if ($visitorId === null) {
        $find = $pdo->prepare('SELECT visitor_id FROM visitors WHERE phone = ? ORDER BY visitor_id DESC LIMIT 1');
        $find->execute([$visit['visitor_phone']]);
        $visitorId = $find->fetchColumn() ?: null;
        if ($visitorId === null) {
            $pdo->prepare('INSERT INTO visitors (full_name, phone) VALUES (?, ?)')
                ->execute([$visit['visitor_name'], $visit['visitor_phone']]);
            $visitorId = $pdo->lastInsertId();
        }
    }

    $pdo->prepare(
        'INSERT INTO gate_entry_logs
           (estate_id, visit_id, visitor_id, staff_id, check_in_time,
            vehicle_plate, entry_method, decision, notes)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?)'
    )->execute([
        $staff['estate_id'], $visitId, $visitorId, $staff['staff_id'],
        $plate, $method, $decision, $notes,
    ]);
    $entryId = (int) $pdo->lastInsertId();

    if ($decision === 'allowed') {
        $pdo->prepare(
            "UPDATE visit_requests SET status = 'used', access_code_hash = NULL, visitor_id = ?
             WHERE visit_id = ?"
        )->execute([$visitorId, $visitId]);
    } else {
        $pdo->prepare('UPDATE visit_requests SET visitor_id = ? WHERE visit_id = ?')
            ->execute([$visitorId, $visitId]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

json_out(['success' => true, 'entry_id' => $entryId, 'decision' => $decision], 201);
