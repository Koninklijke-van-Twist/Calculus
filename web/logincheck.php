<?php

declare(strict_types=1);
require_once __DIR__ . '/kvt_protect_dirs.php';

require_once __DIR__ . '/lib/ActionAccess.php';
require_once __DIR__ . '/lib/ImportStore.php';

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
        $managedEmails = [];
        try {
            $managedEmails = (new ImportStore(__DIR__ . '/data/calculus.sqlite'))->listAllowedEmails();
        } catch (Throwable) {
            $managedEmails = [];
        }
        if (!calculus_email_may_access($email, $managedEmails, $allowedUsers ?? null)) {
            $forbidden = __DIR__ . '/../login/403.php';
            if (is_file($forbidden)) {
                require $forbidden;
                die();
            }
            calculus_deny_web_access();
        }

        // Admin-panel: alleen vaste Calculus-admins, niet $ictUsers uit auth.php.
        $_SESSION['user']['admin'] = calculus_email_is_admin($email);

        $analyticsEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
        $analyticsApiKey = trim((string) ($_SESSION['user']['api_key'] ?? ''));
        $analyticsOid = strtolower(trim((string) ($_SESSION['user']['oid'] ?? '')));
        if ($analyticsEmail !== '' && $analyticsApiKey !== '' && $analyticsOid !== '') {
            $analyticsScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $analyticsHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $analyticsBase = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
            $analyticsUrl = $analyticsScheme . '://' . $analyticsHost . $analyticsBase . '/analytics/analytics.php?' . http_build_query([
                'user_email' => $analyticsEmail,
                'api_key' => $analyticsApiKey,
                'oid' => $analyticsOid,
            ], '', '&', PHP_QUERY_RFC3986);

            if (function_exists('curl_init')) {
                $analyticsCurl = curl_init($analyticsUrl);
                curl_setopt_array($analyticsCurl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 1,
                    CURLOPT_TIMEOUT => 1,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_HTTPHEADER => ['X-API-Key: ' . $analyticsApiKey],
                ]);
                curl_exec($analyticsCurl);
                curl_close($analyticsCurl);
            }
        }
    }
}

calculus_release_session_lock();
