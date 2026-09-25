<?php
/**
 * POST /api/visits/create.php   (resident token)
 * {
 *   "visitor_name": "Tunde Bakare",
 *   "visitor_phone": "08031234567",
 *   "purpose": "Plumbing repair",                 (optional)
 *   "valid_from": "2026-10-01T09:00:00+01:00",    (optional, default: now)
 *   "valid_to":   "2026-10-01T18:00:00+01:00"     (optional, default: +24h)
 * }
 * Returns the 6-digit access code ONCE. Only its hash is stored, so if the
 * resident loses it, they cancel and create a new invite.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_method('POST');
$resident = require_user('resident');

$visitorName = str_field('visitor_name', true, 150);
$visitorPhone = normalize_phone(str_field('visitor_phone'));
$purpose = str_field('purpose', false, 200);

$now = utc_now();
$validFrom = datetime_field('valid_from', false) ?? $now;
$validTo = datetime_field('valid_to', false) ?? $validFrom->modify('+24 hours');

if ($validTo <= $validFrom) fail(422, 'valid_to must be after valid_from');
if ($validTo <= $now) fail(422, 'This invite would already be expired');
$maxHours = config('max_visit_window_hours');
if ($validTo > $validFrom->modify("+{$maxHours} hours")) fail(422, "An invite can't be valid for more than {$maxHours} hours");

// estate_id comes from the resident's own unit, never from the request,
// so nobody can create invites for an estate they don't live in.
$insert = db()->prepare(
    "INSERT INTO visit_requests
       (estate_id, unit_id, created_by_resident_id, visitor_name, visitor_phone,
        purpose, valid_from, valid_to, access_code_hash, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved')"
);

for ($attempt = 1; ; $attempt++) {
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    try {
        $insert->execute([
            $resident['estate_id'], $resident['unit_id'], $resident['resident_id'],
            $visitorName, $visitorPhone, $purpose,
            sql_dt($validFrom), sql_dt($validTo), hash_access_code($code),
        ]);
        break;
    } catch (PDOException $e) {
        // Code already active in this estate: roll again. Fine up to ~tens of
        // thousands of simultaneous active invites per estate.
        if (!is_duplicate_key($e) || $attempt >= 10) throw $e;
    }
}

$host = strtok($resident['full_name'], ' ');
$destination = unit_label($resident['block'], $resident['unit_code']);
$window = $validFrom > $now
    ? 'It works once, from ' . lagos_time($validFrom) . ' until ' . lagos_time($validTo) . '.'
    : 'It works once, until ' . lagos_time($validTo) . '.';

json_out([
    'success' => true,
    'visit_id' => (int) db()->lastInsertId(),
    'access_code' => $code,
    'visitor_name' => $visitorName,
    'visitor_phone' => $visitorPhone,
    'estate_name' => $resident['estate_name'],
    'destination' => $destination,
    'valid_from' => $validFrom->format(DATE_ATOM),
    'valid_to' => $validTo->format(DATE_ATOM),
    'share_message' => "Hi {$visitorName}, {$host} has invited you to {$resident['estate_name']} ({$destination}).\n\n"
        . "Your gate code is {$code}. {$window}\n\n"
        . "Show this code to security at the gate.",
], 201);
