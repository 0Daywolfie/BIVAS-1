<?php
/**
 * GET  /api/admin/staff.php  (admin token)  -> guards and supervisors, with lockout status
 *
 * POST /api/admin/staff.php
 *   { "action": "create", "full_name": "...", "phone": "080...", "role": "guard"|"supervisor", "shift": "Day" }
 *   { "action": "update", "staff_id": 1, ...same fields... }
 *   { "action": "deactivate" | "activate" | "reset_pin" | "unlock", "staff_id": 1 }
 *
 * create and reset_pin return a 6-digit PIN ONCE. unlock clears a
 * wrong-code lockout without deleting the failed attempts (they're evidence).
 */
require_once __DIR__ . '/../lib/bootstrap.php';
$admin = require_user('admin');
$estate = $admin['estate_id'];

function find_staff(int $estate, int $id): array {
    $stmt = db()->prepare(
        "SELECT staff_id, full_name, role, is_active, lockout_cleared_at FROM security_staff
         WHERE staff_id = ? AND estate_id = ? AND role IN ('guard','supervisor')"
    );
    $stmt->execute([$id, $estate]);
    return $stmt->fetch() ?: fail(404, 'No guard with that ID in your estate');
}

function wrong_codes(array $s): int {
    $stmt = db()->prepare('SELECT COUNT(*) FROM verify_attempts WHERE staff_id = ? AND success = FALSE AND attempted_at > ?');
    $stmt->execute([$s['staff_id'], lockout_window_start($s['lockout_cleared_at'])]);
    return (int) $stmt->fetchColumn();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = str_field('action');

    if ($action === 'create' || $action === 'update') {
        $name = str_field('full_name', true, 150);
        $phone = normalize_phone(str_field('phone'));
        $role = str_field('role', false, 20) ?? 'guard';
        if (!in_array($role, ['guard', 'supervisor'], true)) fail(422, 'role must be guard or supervisor');
        $shift = str_field('shift', false, 50);

        try {
            if ($action === 'create') {
                $pin = temp_pin();
                db()->prepare(
                    'INSERT INTO security_staff (estate_id, full_name, phone, pin_hash, role, shift) VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$estate, $name, $phone, password_hash($pin, PASSWORD_DEFAULT), $role, $shift]);
                $id = (int) db()->lastInsertId();
                audit($admin, 'staff.create', 'staff', $id, "Added {$role} {$name}");
                json_out(['success' => true, 'staff_id' => $id, 'pin' => $pin], 201);
            }
            $id = int_field('staff_id');
            find_staff($estate, $id);
            db()->prepare('UPDATE security_staff SET full_name = ?, phone = ?, role = ?, shift = ? WHERE staff_id = ?')
                ->execute([$name, $phone, $role, $shift, $id]);
            audit($admin, 'staff.update', 'staff', $id, "Updated {$role} {$name}");
            json_out(['success' => true]);
        } catch (PDOException $e) {
            if (is_duplicate_key($e)) fail(409, 'That phone number already belongs to another guard');
            throw $e;
        }
    }

    $id = int_field('staff_id');
    $s = find_staff($estate, $id);

    switch ($action) {
        case 'deactivate':
            db()->prepare('UPDATE security_staff SET is_active = FALSE WHERE staff_id = ?')->execute([$id]);
            revoke_tokens('staff', $id);
            audit($admin, 'staff.deactivate', 'staff', $id, "Deactivated {$s['full_name']} and ended their shift session");
            json_out(['success' => true]);

        case 'activate':
            db()->prepare('UPDATE security_staff SET is_active = TRUE WHERE staff_id = ?')->execute([$id]);
            audit($admin, 'staff.activate', 'staff', $id, "Reactivated {$s['full_name']}");
            json_out(['success' => true]);

        case 'reset_pin':
            $pin = temp_pin();
            db()->prepare('UPDATE security_staff SET pin_hash = ? WHERE staff_id = ?')
                ->execute([password_hash($pin, PASSWORD_DEFAULT), $id]);
            revoke_tokens('staff', $id);
            audit($admin, 'staff.reset_pin', 'staff', $id, "Reset PIN for {$s['full_name']}");
            json_out(['success' => true, 'pin' => $pin]);

        case 'unlock':
            $n = wrong_codes($s);
            db()->prepare('UPDATE security_staff SET lockout_cleared_at = UTC_TIMESTAMP() WHERE staff_id = ?')->execute([$id]);
            audit($admin, 'staff.unlock', 'staff', $id, "Cleared lockout for {$s['full_name']} after {$n} wrong code(s)");
            json_out(['success' => true]);

        default:
            fail(422, 'action must be create, update, deactivate, activate, reset_pin or unlock');
    }
}

require_method('GET');
$stmt = db()->prepare(
    "SELECT s.staff_id, s.full_name, s.phone, s.role, s.shift, s.is_active, s.lockout_cleared_at, s.created_at,
            (SELECT MAX(g.check_in_time) FROM gate_entry_logs g WHERE g.staff_id = s.staff_id) AS last_gate_action
     FROM security_staff s
     WHERE s.estate_id = ? AND s.role IN ('guard','supervisor')
     ORDER BY s.is_active DESC, s.full_name"
);
$stmt->execute([$estate]);
$max = (int) config('verify_max_failures');

json_out([
    'success' => true,
    'staff' => array_map(function ($s) use ($max) {
        $fails = $s['is_active'] ? wrong_codes($s) : 0;
        return [
            'staff_id' => (int) $s['staff_id'],
            'full_name' => $s['full_name'],
            'phone' => $s['phone'],
            'role' => $s['role'],
            'shift' => $s['shift'],
            'is_active' => (bool) $s['is_active'],
            'wrong_codes' => $fails,
            'locked_out' => $fails >= $max,
            'last_gate_action' => iso($s['last_gate_action']),
            'created_at' => iso($s['created_at']),
        ];
    }, $stmt->fetchAll()),
]);
