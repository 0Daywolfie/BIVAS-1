<?php
declare(strict_types=1);

// Everything runs in UTC; convert to Lagos time in the UI, not the database.
date_default_timezone_set('UTC');

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/admin.php';

set_exception_handler(function (Throwable $e): void {
    error_log('BIVAS error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail(500, 'Something went wrong on our side');
});
