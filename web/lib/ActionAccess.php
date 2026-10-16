<?php

declare(strict_types=1);

/**
 * Login-gate voor Calculus-acties, zelfde patroon als Hermes odata.php.
 * login/lib.php + $allowedUsers, daarna het sessiebestand loslaten.
 * CLI en een niet-leeg runnergeheim zijn de enige anonieme bypass.
 */

/**
 * @param mixed $allowedUsers
 */
function calculus_email_is_allowed(string $email, mixed $allowedUsers): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }
    // Zelfde semantiek als de oude logincheck: ontbreekt de lijst of is die
    // leeg, dan mag elke ingelogde gebruiker erin.
    if (!is_array($allowedUsers) || $allowedUsers === []) {
        return true;
    }

    foreach ($allowedUsers as $emailKey => $value) {
        $allowedEmail = is_int($emailKey) ? (string) $value : (string) $emailKey;
        if (strtolower(trim($allowedEmail)) === $email) {
            return true;
        }
    }

    return false;
}

function calculus_action_skips_login(string $sapi, string $presentedSecret, string $serverSecret): bool
{
    if ($sapi === 'cli') {
        return true;
    }
    if ($presentedSecret === '' || $serverSecret === '') {
        return false;
    }

    return hash_equals($serverSecret, $presentedSecret);
}

function calculus_presented_runner_secret(): string
{
    $header = $_SERVER['HTTP_X_CALCULUS_SECRET'] ?? '';
    if (!is_string($header)) {
        return '';
    }

    return trim($header);
}

/**
 * @param array<string, mixed> $authVars
 */
function calculus_configured_runner_secret(array $authVars = []): string
{
    $candidates = [];
    if (isset($GLOBALS['calculusRunnerSecret']) && is_string($GLOBALS['calculusRunnerSecret'])) {
        $candidates[] = $GLOBALS['calculusRunnerSecret'];
    }
    if (isset($authVars['calculusRunnerSecret']) && is_string($authVars['calculusRunnerSecret'])) {
        $candidates[] = $authVars['calculusRunnerSecret'];
    }
    $env = getenv('CALCULUS_RUNNER_SECRET');
    if (is_string($env)) {
        $candidates[] = $env;
    }

    foreach ($candidates as $candidate) {
        $secret = trim($candidate);
        if ($secret !== '') {
            return $secret;
        }
    }

    return '';
}

function calculus_auth_php_path(): string
{
    $override = $GLOBALS['CALCULUS_AUTH_PHP_PATH'] ?? null;
    if (is_string($override) && $override !== '') {
        return $override;
    }

    return dirname(__DIR__) . '/auth.php';
}

/**
 * @return array<string, mixed>
 */
function calculus_read_auth_file_vars(?string $path = null): array
{
    $path ??= calculus_auth_php_path();
    if (!is_file($path)) {
        return [];
    }

    $loaded = (static function (string $__path): array {
        ob_start();
        try {
            require $__path;
        } finally {
            ob_end_clean();
        }
        unset($__path);

        return get_defined_vars();
    })($path);

    return is_array($loaded) ? $loaded : [];
}

function calculus_release_session_lock(): void
{
    if (function_exists('session_status') && function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

/**
 * @return array<string, mixed>
 */
function calculus_session_bag(): array
{
    if (!array_key_exists('_SESSION', $GLOBALS) || !is_array($GLOBALS['_SESSION'])) {
        return [];
    }

    return $GLOBALS['_SESSION'];
}

function calculus_persist_session(callable $write): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $write();
    calculus_release_session_lock();
}

/**
 * Loopback blijft voor lokaal ontwikkelen. Een request vanaf het publieke
 * serveradres telt niet: dat is geen nightly-bypass.
 */
function calculus_is_trusted_requester(string $remoteAddr): bool
{
    return $remoteAddr === '127.0.0.1' || $remoteAddr === '::1';
}

/**
 * Nightly/runner: CLI, of header X-Calculus-Secret gelijk aan het servergeheim.
 * Een lege header leest auth.php niet. Geen queryparameter.
 */
function calculus_request_skips_login(): bool
{
    $sapi = php_sapi_name();
    if (!is_string($sapi)) {
        $sapi = '';
    }
    if ($sapi === 'cli') {
        return true;
    }

    $presented = calculus_presented_runner_secret();
    if ($presented === '') {
        return false;
    }

    $serverSecret = calculus_configured_runner_secret(calculus_read_auth_file_vars());

    return calculus_action_skips_login($sapi, $presented, $serverSecret);
}

/**
 * Alleen een xlsx-bestandsnaam binnen $dir. Geen paden, geen sqlite.
 */
function calculus_safe_package_path(string $dir, string $requested): ?string
{
    $base = basename($requested);
    if (!preg_match('/^[A-Za-z0-9._-]+\.xlsx$/', $base)) {
        return null;
    }
    $realDir = realpath($dir);
    if ($realDir === false || !is_dir($realDir)) {
        return null;
    }
    $realPath = realpath($realDir . DIRECTORY_SEPARATOR . $base);
    if (
        $realPath === false
        || !is_file($realPath)
        || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR)
    ) {
        return null;
    }

    return $realPath;
}

function calculus_deny_web_access(): void
{
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo 'Geen toegang';
    exit;
}
