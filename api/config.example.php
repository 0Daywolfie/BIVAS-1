<?php
// Copy to config.php and fill in real values. config.php is gitignored.
return [
    'db_host' => 'localhost',
    'db_name' => 'bivas',
    'db_user' => 'bivas_user',
    'db_pass' => 'change-me',

    // Secret used to HMAC access codes. Generate once with:
    //   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    // Changing it later invalidates every active code.
    'code_pepper' => 'paste-64-hex-chars-here',

    'resident_token_ttl_hours' => 24 * 30, // residents stay logged in ~30 days
    'staff_token_ttl_hours'    => 12,      // guards: one shift

    'max_visit_window_hours'   => 24 * 7,  // an invite can't be valid for more than a week
    'verify_max_failures'      => 5,       // failed code checks allowed per guard...
    'verify_window_minutes'    => 10,      // ...within this many minutes
];
