<?php

// Altijd sessie starten: dry-run preview + download hangen hiervan af,
// ook op localhost waar SSO wordt overgeslagen.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function is_trusted_requester(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $server = $_SERVER['SERVER_ADDR'] ?? '';
    $trusted = ['127.0.0.1', '::1'];
    if ($remote === $server && $remote !== '') {
        return true;
    }
    if (in_array($remote, $trusted, true)) {
        return true;
    }

    return false;
}

if (is_trusted_requester()) {
    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        $_SESSION['user'] = [
            'email' => 'local@dev',
            'name' => 'Local Dev',
        ];
    }
} else {
    require __DIR__ . '/../login/lib.php';

    if (
        isset($allowedUsers) &&
        !array_any($allowedUsers, static function ($email) {
            return strtolower((string) $email) === strtolower((string) ($_SESSION['user']['email'] ?? ''));
        })
    ) {
        require __DIR__ . '/../login/403.php';
        die();
    }

    if (
        isset($ictUsers) &&
        is_array($ictUsers) &&
        array_any($ictUsers, static function ($email) {
            return strtolower((string) $email) === strtolower((string) ($_SESSION['user']['email'] ?? ''));
        })
    ) {
        $_SESSION['user']['admin'] = true;
    }
}
