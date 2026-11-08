<?php
// Router for PHP's built-in server (dev / simple hosting). Serves static files, blocks private dirs.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
// uploads/ is blocked too: photos are only served through index.php?p=file (behind the password), like on the live host.
if (preg_match('#^/(app|data|uploads)(/|$)#', $path)) { http_response_code(403); exit('Forbidden'); }
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($path, '.php')) return false; // let the server stream it
require __DIR__ . '/index.php';
