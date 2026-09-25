<?php
/**
 * CLI only. Marks past-due invites expired and frees their codes.
 * cPanel cron, every 15 minutes:
 *   php /home/YOURUSER/bivas/tools/expire-visits.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../api/lib/db.php';

$n = db()->exec(
    "UPDATE visit_requests SET status = 'expired', access_code_hash = NULL
     WHERE status IN ('pending','approved') AND valid_to < UTC_TIMESTAMP()"
);
$m = db()->exec("DELETE FROM api_tokens WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 7 DAY");
$k = db()->exec("DELETE FROM verify_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 90 DAY");
echo date('c') . " expired $n invites, pruned $m tokens, $k old attempts\n";
