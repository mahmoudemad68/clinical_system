<?php

declare(strict_types=1);

/**
 * php -S router for Admin browser E2E.
 *
 * PHP's built-in server historically treats the Cookie header name as
 * case-sensitive and may leave $_COOKIE empty even when the header is
 * present. Hydrate $_SERVER / $_COOKIE from getallheaders() before Laravel
 * boots. Logs are presence-only (no cookie values, tokens, or ids).
 */
$publicPath = getcwd();

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '');

if ($uri !== '/' && is_file($publicPath.$uri)) {
    return false;
}

$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
foreach ($headers as $name => $value) {
    if (! is_string($name) || ! is_string($value) || $value === '') {
        continue;
    }

    $serverKey = 'HTTP_'.strtoupper(str_replace('-', '_', $name));
    if (! isset($_SERVER[$serverKey])) {
        $_SERVER[$serverKey] = $value;
    }

    $lower = strtolower($name);
    if ($lower === 'content-type' && ! isset($_SERVER['CONTENT_TYPE'])) {
        $_SERVER['CONTENT_TYPE'] = $value;
    }
    if ($lower === 'content-length' && ! isset($_SERVER['CONTENT_LENGTH'])) {
        $_SERVER['CONTENT_LENGTH'] = $value;
    }
}

$cookieHeader = $_SERVER['HTTP_COOKIE'] ?? '';
if ((! isset($_COOKIE) || $_COOKIE === []) && is_string($cookieHeader) && $cookieHeader !== '') {
    foreach (explode(';', $cookieHeader) as $part) {
        $part = trim($part);
        if ($part === '' || ! str_contains($part, '=')) {
            continue;
        }

        [$cookieName, $cookieValue] = explode('=', $part, 2);
        $cookieName = trim($cookieName);
        if ($cookieName === '') {
            continue;
        }

        $_COOKIE[$cookieName] = urldecode($cookieValue);
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$hasCookie = is_string($cookieHeader) && $cookieHeader !== '' ? '1' : '0';
$hasXsrf = isset($_SERVER['HTTP_X_XSRF_TOKEN']) || isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? '1' : '0';
$hasCtype = isset($_SERVER['CONTENT_TYPE']) || isset($_SERVER['HTTP_CONTENT_TYPE']) ? '1' : '0';
$cookieBag = isset($_COOKIE) && $_COOKIE !== [] ? '1' : '0';
file_put_contents(
    'php://stderr',
    "php-router method={$method} cookie={$hasCookie} xsrf={$hasXsrf} ctype={$hasCtype} cookie_bag={$cookieBag}\n",
);

require_once $publicPath.'/index.php';
