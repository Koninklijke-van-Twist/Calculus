<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/ActionAccess.php';

// Preview en download hangen aan de sessie. De lock gaat direct na deze
// check dicht, zodat een trage BC-call andere verzoeken van dezelfde
// gebruiker niet blokkeert. De preview wordt later kort heropend om te bewaren.
// CLI en een runnergeheim slaan SSO over (nightly). Loopback blijft voor lokaal.

if (!calculus_request_skips_login()) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($remote)) {
        $remote = '';
    }

    if (calculus_is_trusted_requester($remote)) {
        if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
            $_SESSION['user'] = [
                'email' => 'local@dev',
                'name' => 'Local Dev',
            ];
        }
    } else {
        $loginLib = __DIR__ . '/../login/lib.php';
        if (!is_file($loginLib)) {
            calculus_deny_web_access();
        }
        require $loginLib;

        $email = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
        if (!calculus_email_is_allowed($email, $allowedUsers ?? null)) {
            $forbidden = __DIR__ . '/../login/403.php';
            if (is_file($forbidden)) {
                require $forbidden;
                die();
            }
            calculus_deny_web_access();
        }

        if (isset($ictUsers) && calculus_email_is_allowed($email, $ictUsers)) {
            $_SESSION['user']['admin'] = true;
        }
    }
}

calculus_release_session_lock();
