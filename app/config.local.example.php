<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit; }
// Copy to app/config.local.php (ignored by git). Everything here is optional.
// define('DATA_DIR', '/var/lib/garage');            // SQLite database (+ WAL files), outside the web root
// define('UPLOAD_DIR', '/var/lib/garage/uploads');  // photos and receipts, streamed only to their owner
// define('APP_NAME', 'Open Garage');
//
// Hosted mode: email sign-up, every account sees only its own vehicles.
// define('MULTI_USER', true);
// define('BASE_URL', 'https://garage.example.com');      // used in emailed links
// define('MAIL_KEY', 're_...');                           // Resend API key (sending access)
// define('MAIL_FROM', 'Open Garage <hello@example.com>');
// define('SIGNUP_OPEN', true);
// define('LIMIT_VEHICLES', 10); define('LIMIT_PHOTOS', 400); define('LIMIT_VISION_PER_DAY', 25);
//
// Showroom photos / 3D (see vision/README.md).
// define('VISION_URL', 'http://100.x.y.z:8773');
// define('VISION_TOKEN', '...');
// define('VISION_MESH', false);
//
// Keep secrets out of synced or shared folders, e.g.:
// if (is_file('/var/lib/garage/secrets.php')) require '/var/lib/garage/secrets.php';
