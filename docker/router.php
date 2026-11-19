<?php

/*
 * PHP built-in server router for Untasked Fly images.
 * Returning false for real files under public/ lets Vite assets load; everything
 * else goes through Laravel. Do not pass public/index.php as the -S router —
 * that script never returns false, so /build/assets/* becomes HTML and the
 * browser shows a blank Inertia shell.
 */
$publicPath = dirname(__DIR__).'/public';

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

if ($uri !== '/' && file_exists($publicPath.$uri) && ! is_dir($publicPath.$uri)) {
    return false;
}

require_once $publicPath.'/index.php';
