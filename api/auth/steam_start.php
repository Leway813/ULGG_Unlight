<?php

declare(strict_types=1);

const STEAM_AUTH_BASE_URL = 'https://ulgg.online';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (
    empty($_SESSION['login_redirect'])
    || !is_string($_SESSION['login_redirect'])
) {
    $_SESSION['login_redirect'] = '/pages/index.php';
}

$state = bin2hex(random_bytes(32));
$_SESSION['steam_auth_state'] = $state;
$_SESSION['steam_auth_started_at'] = time();

$returnTo = STEAM_AUTH_BASE_URL
    . '/api/auth/steam_callback.php?state='
    . rawurlencode($state);

$params = http_build_query(
    [
        'openid.ns' => 'http://specs.openid.net/auth/2.0',
        'openid.mode' => 'checkid_setup',
        'openid.return_to' => $returnTo,
        'openid.realm' => STEAM_AUTH_BASE_URL,
        'openid.identity' =>
            'http://specs.openid.net/auth/2.0/identifier_select',
        'openid.claimed_id' =>
            'http://specs.openid.net/auth/2.0/identifier_select',
    ],
    '',
    '&',
    PHP_QUERY_RFC3986
);

header('Cache-Control: no-store');
header(
    'Location: https://steamcommunity.com/openid/login?' . $params,
    true,
    302
);
exit;
