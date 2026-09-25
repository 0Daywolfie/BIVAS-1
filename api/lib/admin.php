<?php
declare(strict_types=1);

/** Records one admin action. Call it AFTER the change succeeds. */
function audit(array $admin, string $action, ?string $targetType, ?int $targetId, string $summary): void {
    db()->prepare(
        'INSERT INTO admin_audit_log
           (estate_id, admin_id, action, target_type, target_id, summary, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
    )->execute([
        $admin['estate_id'], $admin['admin_id'], $action, $targetType, $targetId,
        mb_substr_safe($summary, 300), substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
    ]);
}

function mb_substr_safe(string $s, int $len): string {
    return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
}

/**
 * One-time password for a resident, shown to the admin exactly once, e.g. "kp7m-xq3v-r9tw".
 * No look-alike characters (0/o, 1/l/i), so it survives being read aloud or over WhatsApp.
 */
function temp_password(): string {
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 12; $i++) {
        if ($i > 0 && $i % 4 === 0) $out .= '-';
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

/** One-time 6-digit guard PIN; rejects trivially guessable ones like 111111 or 123456. */
function temp_pin(): string {
    do {
        $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    } while (count(array_unique(str_split($pin))) < 3 || str_contains('0123456789', $pin) || str_contains('9876543210', $pin));
    return $pin;
}

/** Converts a Lagos calendar date (YYYY-MM-DD) to the UTC instant it starts at. */
function lagos_day_start_utc(string $date): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail(422, 'Dates must look like 2026-09-25');
    try {
        $d = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('Africa/Lagos'));
    } catch (Exception) {
        fail(422, 'Invalid date');
    }
    return sql_dt($d->setTimezone(new DateTimeZone('UTC')));
}

/** Optional query-string text filter, trimmed and length-capped. */
function query_text(string $key, int $maxLen = 100): ?string {
    $v = trim((string) ($_GET[$key] ?? ''));
    return $v === '' ? null : substr($v, 0, $maxLen);
}

/** Escapes % and _ so user text is matched literally inside LIKE. */
function like_pattern(string $s): string {
    return '%' . addcslashes($s, '%_\\') . '%';
}
