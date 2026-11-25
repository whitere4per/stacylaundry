<?php
define('APP_NAME', 'Stacey Laundry Hub');
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'stacey_laundry_hub');
define('DB_USER', getenv('DB_USER') ?: 'stacey_app');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '2a7003aa0f23f5c2ece82883041c10d1');
