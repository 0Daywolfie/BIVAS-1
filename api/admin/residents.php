<?php
/**
 * GET  /api/admin/residents.php  (admin token)  ?q=adaeze  -> residents + units in this estate
 *
 * POST /api/admin/residents.php
 *   { "action": "create", "full_name": "...", "phone": "080...", "email": "...", "unit_code": "B12", "block": "B" }
 *   { "action": "update", "resident_id": 1, ...same fields... }
 *   { "action": "deactivate" | "activate" | "reset_password", "resident_id": 1 }
 *
 * create and reset_password return a temporary password ONCE. The admin
 * passes it on; it is never stored in readable form. Deactivating a resident
 * signs them out everywhere and cancels their active invites, so a tenant
 * who moved out can't keep letting people in.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
$admin = require_user('admin');
$estate = $admin['estate_id'];

/** Loads one resident, but only from this admin's estate. */
function find_resident(int $estate, int $id): array {
    $stmt = db()->prepare(
        'SELECT r.resident_id, r.full_name, r.phone, r.is_active, u.unit_code, u.block
         FROM residents r JOIN units u ON u.unit_id = r.unit_id
         WHERE r.resident_id = ? AND u.estate_id = ?'
    );
    $stmt->execute([$id, $estate]);
    return $stmt->fetch() ?: fail(404, 'No resident with that ID in your estate');
}

/** Finds the unit by code, or creates it, always inside this estate. */
function unit_for(int $estate, string $unitCode, ?string $block): int {
    $unitCode = strtoupper(preg_replace('/\s+/', '', $unitCode));
    $block = $block === null ? null : strtoupper(trim($block));
    $stmt = db()->prepare('SELECT unit_id, block FROM units WHERE estate_id = ? AND unit_code = ?');
    $stmt->execute([$estate, $unitCode]);
    if ($unit = $stmt->fetch()) {
        if ($block !== null && $block !== $unit['block']) {
            db()->prepare('UPDATE units SET block = ? WHERE unit_id = ?')->execute([$block, $unit['unit_id']]);
        }
        return (int) $unit['unit_id'];
    }
    db()->prepare('INSERT INTO units (estate_id, unit_code, block) VALUES (?, ?, ?)')->execute([$estate, $unitCode, $block]);
    return (int) db()->lastInsertId();
}

function email_field(): ?string {
    $email = str_field('email', false, 150);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail(422, 'Enter a valid email address or leave it empty');
    return $email;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = str_field('action');

    if ($action === 'create' || $action === 'update') {
        $name = str_field('full_name', true, 150);
        $phone = normalize_phone(str_field('phone'));
        $email = email_field();
        $unitCode = str_field('unit_code', true, 50);
        $unitId = unit_for($estate, $unitCode, str_field('block', false, 50));

        try {
            if ($action === 'create') {
                $temp = temp_password();
                db()->prepare(
                    'INSERT INTO residents (unit_id, full_name, phone, email, password_hash) VALUES (?, ?, ?, ?, ?)'
                )->execute([$unitId, $name, $phone, $email, password_hash($temp, PASSWORD_DEFAULT)]);
                $id = (int) db()->lastInsertId();
                $r = find_resident($estate, $id);
                audit($admin, 'resident.create', 'resident', $id, "Added {$name} (" . unit_label($r['block'], $r['unit_code']) . ')');
                json_out(['success' => true, 'resident_id' => $id, 'temp_password' => $temp], 201);
            }
            $id = int_field('resident_id');
            find_resident($estate, $id);
            db()->prepare('UPDATE residents SET unit_id = ?, full_name = ?, phone = ?, email = ? WHERE resident_id = ?')
                ->execute([$unitId, $name, $phone, $email, $id]);
            $r = find_resident($estate, $id);
            audit($admin, 'resident.update', 'resident', $id, "Updated {$name} (" . unit_label($r['block'], $r['unit_code']) . ')');
            json_out(['success' => true]);
        } catch (PDOException $e) {
            if (is_duplicate_key($e)) fail(409, 'That phone number already belongs to another resident');
            throw $e;
        }
    }

    $id = int_field('resident_id');
    $r = find_resident($estate, $id);
    $who = "{$r['full_name']} (" . unit_label($r['block'], $r['unit_code']) . ')';

    switch ($action) {
        case 'deactivate':
            db()->prepare('UPDATE residents SET is_active = FALSE WHERE resident_id = ?')->execute([$id]);
            revoke_tokens('resident', $id);
            $cancel = db()->prepare(
                "UPDATE visit_requests SET status = 'cancelled', access_code_hash = NULL
                 WHERE created_by_resident_id = ? AND status IN ('pending','approved')"
            );
            $cancel->execute([$id]);
            $n = $cancel->rowCount();
            audit($admin, 'resident.deactivate', 'resident', $id, "Deactivated {$who}; cancelled {$n} active invite(s)");
            json_out(['success' => true, 'invites_cancelled' => $n]);

        case 'activate':
            db()->prepare('UPDATE residents SET is_active = TRUE WHERE resident_id = ?')->execute([$id]);
            audit($admin, 'resident.activate', 'resident', $id, "Reactivated {$who}");
            json_out(['success' => true]);

        case 'reset_password':
            $temp = temp_password();
            db()->prepare('UPDATE residents SET password_hash = ? WHERE resident_id = ?')
                ->execute([password_hash($temp, PASSWORD_DEFAULT), $id]);
            revoke_tokens('resident', $id);
            audit($admin, 'resident.reset_password', 'resident', $id, "Reset password for {$who}");
            json_out(['success' => true, 'temp_password' => $temp]);

        default:
            fail(422, 'action must be create, update, deactivate, activate or reset_password');
    }
}

require_method('GET');
$where = ['u.estate_id = ?'];
$params = [$estate];
if ($q = query_text('q')) {
    $where[] = '(r.full_name LIKE ? OR r.phone LIKE ? OR u.unit_code LIKE ?)';
    array_push($params, like_pattern($q), like_pattern($q), like_pattern($q));
}
$stmt = db()->prepare(
    "SELECT r.resident_id, r.full_name, r.phone, r.email, r.is_active, r.password_hash IS NOT NULL AS can_sign_in,
            r.created_at, u.unit_id, u.unit_code, u.block,
            (SELECT COUNT(*) FROM visit_requests v
              WHERE v.created_by_resident_id = r.resident_id AND v.status IN ('pending','approved')
                AND v.valid_to > UTC_TIMESTAMP()) AS active_invites
     FROM residents r JOIN units u ON u.unit_id = r.unit_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY r.is_active DESC, u.unit_code, r.full_name LIMIT 500"
);
$stmt->execute($params);

$units = db()->prepare('SELECT unit_id, unit_code, block, status FROM units WHERE estate_id = ? ORDER BY unit_code');
$units->execute([$estate]);

json_out([
    'success' => true,
    'residents' => array_map(fn($r) => [
        'resident_id' => (int) $r['resident_id'],
        'full_name' => $r['full_name'],
        'phone' => $r['phone'],
        'email' => $r['email'],
        'unit_code' => $r['unit_code'],
        'block' => $r['block'],
        'unit' => unit_label($r['block'], $r['unit_code']),
        'is_active' => (bool) $r['is_active'],
        'can_sign_in' => (bool) $r['can_sign_in'],
        'active_invites' => (int) $r['active_invites'],
        'created_at' => iso($r['created_at']),
    ], $stmt->fetchAll()),
    'units' => array_map(fn($u) => [
        'unit_id' => (int) $u['unit_id'],
        'unit_code' => $u['unit_code'],
        'block' => $u['block'],
        'label' => unit_label($u['block'], $u['unit_code']),
        'status' => $u['status'],
    ], $units->fetchAll()),
]);
