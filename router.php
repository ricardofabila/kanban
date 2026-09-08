<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in web server:
 *
 *     php -S 127.0.0.1:8000 router.php
 *
 * The built-in server ignores .htaccess, so without this it happily serves
 * everything under data/ — the database, the backups, and the session files,
 * any one of which is a working login. Apache and nginx are covered by
 * data/.htaccess and your server config respectively; this covers development.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);

// Normalise separators so an encoded or back-slashed path cannot slip past.
$path = str_replace('\\', '/', $path);

if (preg_match('#(^|/)data(/|$)#i', $path) || preg_match('#(^|/)\.#', $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    return true;
}

return false; // anything else: let the server serve it as usual
