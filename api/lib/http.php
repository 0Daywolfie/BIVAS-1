<?php
declare(strict_types=1);

function json_out(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(int $status, string $message, array $extra = []): never {
    json_out(['success' => false, 'error' => $message] + $extra, $status);
}

function require_method(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        fail(405, "Use $method for this endpoint");
    }
}

/** Accepts JSON bodies (apps) or form posts (plain HTML forms). */
function input(): array {
    static $data = null;
    if ($data === null) {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        $data = is_array($decoded) ? $decoded : $_POST;
    }
    return $data;
}

function str_field(string $key, bool $required = true, int $maxLen = 255): ?string {
    $value = input()[$key] ?? null;
    $value = is_scalar($value) ? trim((string) $value) : '';
    if ($value === '') {
        if ($required) fail(422, "$key is required");
        return null;
    }
    $len = function_exists('mb_strlen') ? mb_strlen($value) : strlen(utf8_decode_safe($value));
    if ($len > $maxLen) fail(422, "$key is too long (max $maxLen characters)");
    return $value;
}

/** Character count without requiring the mbstring extension. */
function utf8_decode_safe(string $s): string {
    return preg_replace('/[\x{80}-\x{10FFFF}]/u', '?', $s) ?? $s;
}

function int_field(string $key): int {
    $value = filter_var(input()[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value === false) fail(422, "$key must be a positive integer");
    return $value;
}

/** Parses an ISO-8601 datetime (e.g. 2026-10-01T14:00:00+01:00) into UTC. */
function datetime_field(string $key, bool $required = true): ?DateTimeImmutable {
    $raw = str_field($key, $required, 40);
    if ($raw === null) return null;
    try {
        return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC'));
    } catch (Exception) {
        fail(422, "$key must be a valid ISO-8601 datetime, e.g. 2026-10-01T14:00:00+01:00");
    }
}

/** Nigerian numbers: 080..., +23480..., 23480... -> +23480... */
function normalize_phone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if (str_starts_with($digits, '234')) $digits = substr($digits, 3);
    if (str_starts_with($digits, '0')) $digits = substr($digits, 1);
    if (!preg_match('/^[789]\d{9}$/', $digits)) fail(422, 'Enter a valid Nigerian phone number');
    return '+234' . $digits;
}

function utc_now(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function sql_dt(DateTimeImmutable $dt): string {
    return $dt->format('Y-m-d H:i:s');
}

function iso(?string $sqlDatetime): ?string {
    return $sqlDatetime === null ? null
        : (new DateTimeImmutable($sqlDatetime, new DateTimeZone('UTC')))->format(DATE_ATOM);
}

/** "Unit B12", or "Block C, Unit 4" when the block isn't already part of the unit code. */
function unit_label(?string $block, string $unitCode): string {
    $block = trim((string) $block);
    if ($block === '' || stripos($unitCode, $block) === 0) return "Unit {$unitCode}";
    return "Block {$block}, Unit {$unitCode}";
}

/** Human time in Lagos, e.g. "26 Sep, 4:27 pm". */
function lagos_time(DateTimeImmutable $dt): string {
    return $dt->setTimezone(new DateTimeZone('Africa/Lagos'))->format('j M, g:i a');
}
