<?php

declare(strict_types=1);

/**
 * Acties zijn niet anoniem, data/cache/uploads zijn dicht, FTP laat sqlite met rust.
 * Run: php tests/action_access_test.php
 */

require dirname(__DIR__) . '/web/lib/ActionAccess.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_same($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fail($label . ' verwacht ' . var_export($expected, true) . ' kreeg ' . var_export($actual, true));
    }
}

function assert_contains(string $haystack, string $needle, string $label): void
{
    if (strpos($haystack, $needle) === false) {
        fail($label . ' mist "' . $needle . '"');
    }
}

function assert_not_contains(string $haystack, string $needle, string $label): void
{
    if (strpos($haystack, $needle) !== false) {
        fail($label . ' bevat "' . $needle . '"');
    }
}

$root = dirname(__DIR__);

$allowed = [
    'analyst@kvt.nl',
    'planner@kvt.nl' => [15, 40],
];
assert_same(true, calculus_email_is_allowed('Analyst@kvt.nl', $allowed), 'lijst-gebruiker');
assert_same(true, calculus_email_is_allowed('planner@kvt.nl', $allowed), 'map-gebruiker');
assert_same(false, calculus_email_is_allowed('andere@kvt.nl', $allowed), 'niet op de lijst');
assert_same(false, calculus_email_is_allowed('', $allowed), 'lege sessie');
assert_same(true, calculus_email_is_allowed('analyst@kvt.nl', null), 'geen whitelist: elke ingelogde gebruiker');
assert_same(true, calculus_email_is_allowed('analyst@kvt.nl', []), 'lege whitelist: elke ingelogde gebruiker');
assert_same(false, calculus_email_is_allowed('', null), 'geen whitelist, geen sessie');
assert_same(true, calculus_email_is_allowed('opesket@kvt.nl', ['andere@kvt.nl']), 'vaste admin altijd allowed');
assert_same(true, calculus_email_is_admin('Opesket@kvt.nl'), 'admin opesket');
assert_same(true, calculus_email_is_admin('tfalken@kvt.nl'), 'admin tfalken');
assert_same(false, calculus_email_is_admin('tim@kvt.nl'), 'tim is geen vaste admin');
assert_same(
    ['analyst@kvt.nl', 'planner@kvt.nl'],
    calculus_normalize_allowed_emails($allowed),
    'normaliseer allowlist'
);
assert_same(true, calculus_email_may_access('opesket@kvt.nl', [], ['niemand@kvt.nl']), 'admin mag altijd');
assert_same(true, calculus_email_may_access('analyst@kvt.nl', [], $allowed), 'lege store → auth.php');
assert_same(false, calculus_email_may_access('andere@kvt.nl', [], $allowed), 'lege store, niet in auth');
assert_same(true, calculus_email_may_access('nieuw@kvt.nl', ['nieuw@kvt.nl'], $allowed), 'store wint');
assert_same(false, calculus_email_may_access('analyst@kvt.nl', ['nieuw@kvt.nl'], $allowed), 'niet in store na beheer');

assert_same(true, calculus_action_skips_login('cli', '', ''), 'cli zonder geheim');
assert_same(false, calculus_action_skips_login('fpm-fcgi', '', ''), 'web anoniem');
assert_same(false, calculus_action_skips_login('fpm-fcgi', '', 'server-secret'), 'web zonder header');
assert_same(false, calculus_action_skips_login('fpm-fcgi', 'server-secret', ''), 'leeg servergeheim');
assert_same(false, calculus_action_skips_login('fpm-fcgi', 'nope', 'server-secret'), 'fout geheim');
assert_same(true, calculus_action_skips_login('cgi-fcgi', 'server-secret', 'server-secret'), 'bestaand servergeheim');
assert_same(false, calculus_action_skips_login('apache2handler', 'server-secret', 'ander-geheim'), 'geheim komt niet overeen');
assert_same(false, calculus_action_skips_login('cli-server', '', ''), 'built-in server is geen CLI-bypass');

assert_same(true, calculus_is_trusted_requester('127.0.0.1'), 'loopback v4');
assert_same(true, calculus_is_trusted_requester('::1'), 'loopback v6');
assert_same(false, calculus_is_trusted_requester('203.0.113.50'), 'publiek adres');
assert_same(false, calculus_is_trusted_requester('198.51.100.10'), 'serveradres is geen bypass');

$savedHeader = $_SERVER['HTTP_X_CALCULUS_SECRET'] ?? null;
$savedGetSecret = $_GET['secret'] ?? null;
$_SERVER['HTTP_X_CALCULUS_SECRET'] = '  uit-de-header  ';
$_GET['secret'] = 'uit-de-query';
assert_same('uit-de-header', calculus_presented_runner_secret(), 'header, geen query');
$_SERVER['HTTP_X_CALCULUS_SECRET'] = ['uit-de-header'];
assert_same('', calculus_presented_runner_secret(), 'header moet een string zijn');
if ($savedHeader === null) {
    unset($_SERVER['HTTP_X_CALCULUS_SECRET']);
} else {
    $_SERVER['HTTP_X_CALCULUS_SECRET'] = $savedHeader;
}
if ($savedGetSecret === null) {
    unset($_GET['secret']);
} else {
    $_GET['secret'] = $savedGetSecret;
}

$authPath = sys_get_temp_dir() . '/calculus-auth-runner-secret.php';
file_put_contents($authPath, "<?php\n\$calculusRunnerSecret = \"auth-php-geheim\";\n\$allowedUsers = ['nacht@kvt.nl'];\n");
$previousAuthPath = $GLOBALS['CALCULUS_AUTH_PHP_PATH'] ?? null;
$previousRunner = $GLOBALS['calculusRunnerSecret'] ?? null;
unset($GLOBALS['calculusRunnerSecret']);
$previousEnv = getenv('CALCULUS_RUNNER_SECRET');
putenv('CALCULUS_RUNNER_SECRET');
$GLOBALS['CALCULUS_AUTH_PHP_PATH'] = $authPath;
$fromFile = calculus_read_auth_file_vars();
assert_same('auth-php-geheim', calculus_configured_runner_secret($fromFile), 'geheim uit auth.php');
assert_same(true, calculus_email_is_allowed('nacht@kvt.nl', $fromFile['allowedUsers'] ?? null), 'whitelist uit auth.php');
putenv('CALCULUS_RUNNER_SECRET=env-geheim');
assert_same('auth-php-geheim', calculus_configured_runner_secret($fromFile), 'auth.php wint van een lege global');
$GLOBALS['calculusRunnerSecret'] = 'global-geheim';
assert_same('global-geheim', calculus_configured_runner_secret($fromFile), 'global wint');
unset($GLOBALS['calculusRunnerSecret']);
putenv('CALCULUS_RUNNER_SECRET');
if ($previousAuthPath === null) {
    unset($GLOBALS['CALCULUS_AUTH_PHP_PATH']);
} else {
    $GLOBALS['CALCULUS_AUTH_PHP_PATH'] = $previousAuthPath;
}
if (is_string($previousRunner)) {
    $GLOBALS['calculusRunnerSecret'] = $previousRunner;
}
if ($previousEnv !== false && $previousEnv !== '') {
    putenv('CALCULUS_RUNNER_SECRET=' . $previousEnv);
}
@unlink($authPath);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['calculus_access_probe'] = 1;
calculus_release_session_lock();
if (session_status() === PHP_SESSION_ACTIVE) {
    fail('actieve sessie blijft gelockt');
}
assert_same(1, calculus_session_bag()['calculus_access_probe'] ?? null, 'sessiedata blijft leesbaar na write_close');
calculus_persist_session(static function (): void {
    $_SESSION['calculus_access_probe'] = 2;
});
if (session_status() === PHP_SESSION_ACTIVE) {
    fail('persist laat de sessie gelockt');
}
session_start();
assert_same(2, $_SESSION['calculus_access_probe'] ?? null, 'preview-schrijf komt op schijf');
calculus_release_session_lock();

$packageDir = sys_get_temp_dir() . '/calculus-packages-' . bin2hex(random_bytes(4));
mkdir($packageDir);
$outside = sys_get_temp_dir() . '/calculus-secret-' . bin2hex(random_bytes(4)) . '.sqlite';
file_put_contents($packageDir . '/PRJ2608376_20261016.xlsx', 'excel-bytes');
file_put_contents($outside, 'sqlite-secret');
$safe = calculus_safe_package_path($packageDir, 'PRJ2608376_20261016.xlsx');
if ($safe === null || !str_ends_with($safe, 'PRJ2608376_20261016.xlsx')) {
    fail('geldig pakket moet downloadbaar zijn');
}
assert_same(null, calculus_safe_package_path($packageDir, '../' . basename($outside)), 'sqlite buiten de map');
assert_same(null, calculus_safe_package_path($packageDir, '../../calculus.sqlite'), 'pad omhoog');
assert_same(null, calculus_safe_package_path($packageDir, 'pakket.xlsx.php'), 'geen php');
assert_same(null, calculus_safe_package_path($packageDir, 'ontbreekt.xlsx'), 'ontbrekend bestand');
assert_same(null, calculus_safe_package_path($packageDir, ''), 'lege naam');
@unlink($packageDir . '/PRJ2608376_20261016.xlsx');
@rmdir($packageDir);
@unlink($outside);

$index = (string) file_get_contents($root . '/web/index.php');
$loginAt = strpos($index, "require __DIR__ . '/logincheck.php';");
$actionAt = strpos($index, '$action = trim');
$downloadAt = strpos($index, "\$action === 'download_package'");
if ($loginAt === false || $actionAt === false || $downloadAt === false) {
    fail('index mist login of download_package');
}
if ($loginAt > $actionAt || $loginAt > $downloadAt) {
    fail('logincheck moet vóór de acties en de pakketdownload');
}
assert_contains($index, 'calculus_safe_package_path', 'download via gecontroleerd pad');
assert_contains($index, 'calculus_persist_session', 'preview bewaart de sessie kort');
assert_not_contains($index, 'href="data/packages', 'UI linkt niet naar de map');
assert_not_contains($index, 'href="data/', 'UI linkt niet naar data');
assert_contains($index, 'action=download_package', 'Excel blijft via het PHP-endpoint');
assert_not_contains($index, 'cache_status', 'geen cache_status');
assert_not_contains($index, 'attributes', 'geen voorbeeld-attributes');
if (is_file($root . '/web/odata.php')) {
    fail('Calculus hoort geen odata.php te hebben');
}

$login = (string) file_get_contents($root . '/web/logincheck.php');
$emailAt = strpos($login, 'calculus_email_may_access');
$releaseAt = strpos($login, 'calculus_release_session_lock()');
$libAt = strpos($login, 'login/lib.php');
if ($emailAt === false || $releaseAt === false || $libAt === false || $releaseAt < $emailAt) {
    fail('sessielock moet vrij na de logincheck');
}
assert_contains($login, 'calculus_request_skips_login()', 'cli of runnergeheim');
assert_contains($login, 'calculus_is_trusted_requester', 'loopback voor lokaal');
assert_contains($login, 'calculus_email_may_access', 'effectieve allowlist');
assert_contains($login, 'calculus_email_is_admin', 'vaste admins');
assert_not_contains($login, '$remote === $server', 'geen remote==server bypass');
assert_not_contains($login, '$_GET', 'geheim niet uit de query');
assert_contains($login, 'calculus_deny_web_access()', 'ontbrekende login-lib geeft geen data');

$indexAdmin = (string) file_get_contents($root . '/web/index.php');
assert_contains($indexAdmin, "\$action === 'admin'", 'admin-panel actie');
assert_contains($indexAdmin, 'admin_add_user', 'gebruiker toevoegen');
assert_contains($indexAdmin, 'admin_remove_user', 'gebruiker intrekken');
assert_contains($indexAdmin, 'calculus_require_admin', 'admin-gate op acties');
assert_contains($indexAdmin, 'Gebruikersbeheer', 'admin-UI');

$access = (string) file_get_contents($root . '/web/lib/ActionAccess.php');
assert_contains($access, 'session_write_close()', 'sessie wordt gesloten');
assert_contains($access, 'HTTP_X_CALCULUS_SECRET', 'runner-header');
assert_contains($access, "=== 'cli'", 'cli-vergelijking');
assert_not_contains($access, '$_GET', 'geen querygeheim');
assert_not_contains($access, '$remote === $server', 'geen impliciete server-bypass');
assert_contains($access, "=== '127.0.0.1'", 'alleen loopback');

$guardDirs = [
    $root . '/web/data',
    $root . '/web/data/packages',
    $root . '/web/cache',
    $root . '/web/uploads',
];
foreach ($guardDirs as $dir) {
    $htaccess = $dir . '/.htaccess';
    $rules = (string) file_get_contents($htaccess);
    assert_contains($rules, 'Options -Indexes', $htaccess);
    assert_contains($rules, 'Require all denied', $htaccess);
    assert_contains($rules, 'Deny from all', $htaccess);
    assert_contains($rules, '<IfModule mod_authz_core.c>', $htaccess);
    assert_contains($rules, '<IfModule !mod_authz_core.c>', $htaccess);
    foreach (['index.html', 'index.php'] as $indexName) {
        $indexFile = $dir . '/' . $indexName;
        if (!is_file($indexFile)) {
            fail('ontbrekend indexbestand ' . $indexFile);
        }
        $indexBody = (string) file_get_contents($indexFile);
        if (strlen($indexBody) > 16) {
            fail('indexbestand is niet leeg: ' . $indexFile);
        }
        assert_not_contains($indexBody, 'sqlite', $indexFile);
        assert_not_contains($indexBody, 'xlsx', $indexFile);
    }
}

// FTP-deploy en afscherming: zie tests/protect_dirs_test.php.

$tracked = [
    'web/data/.htaccess',
    'web/data/index.html',
    'web/data/index.php',
    'web/data/packages/.htaccess',
    'web/data/packages/index.html',
    'web/data/packages/index.php',
    'web/cache/.htaccess',
    'web/cache/index.html',
    'web/cache/index.php',
    'web/uploads/.htaccess',
    'web/uploads/index.html',
    'web/uploads/index.php',
];
$ignored = [
    'web/data/calculus.sqlite',
    'web/data/packages/PRJ2608376_20261016.xlsx',
    'web/cache/odata.json',
    'web/uploads/bron.xlsx',
    'web/auth.php',
];
foreach ($tracked as $path) {
    exec('git -C ' . escapeshellarg($root) . ' check-ignore -q -- ' . escapeshellarg($path), $unused, $code);
    if ($code === 0) {
        fail('git negeert afscherming ' . $path);
    }
}
foreach ($ignored as $path) {
    exec('git -C ' . escapeshellarg($root) . ' check-ignore -q -- ' . escapeshellarg($path), $unused, $code);
    if ($code !== 0) {
        fail('git moet runtime-data negeren ' . $path);
    }
}

$cliScript = $root . '/web/logincheck.php';
exec('php ' . escapeshellarg($cliScript), $cliOut, $cliCode);
$cliText = implode("\n", $cliOut);
if ($cliCode !== 0) {
    fail('CLI-logincheck exit ' . $cliCode . ' ' . $cliText);
}
assert_not_contains($cliText, 'Geen toegang', 'CLI-logincheck');
assert_not_contains($cliText, 'failed to open', 'CLI-logincheck');

$phpCgi = trim((string) shell_exec('command -v php-cgi 2>/dev/null'));
if ($phpCgi !== '') {
    $script = sys_get_temp_dir() . '/calculus-login-cgi-' . bin2hex(random_bytes(4)) . '.php';
    $loginFile = $root . '/web/logincheck.php';
    file_put_contents($script, "<?php\n"
        . '$_SERVER["REMOTE_ADDR"] = "203.0.113.50";' . "\n"
        . '$_SERVER["SERVER_ADDR"] = "198.51.100.10";' . "\n"
        . '$_GET["secret"] = "mag-niet";' . "\n"
        . '$_GET["action"] = "download_package";' . "\n"
        . 'require ' . var_export($loginFile, true) . ";\n"
        . "echo \"SHOULD_NOT_REACH\";\n");
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([$phpCgi, '-q', '-d', 'display_errors=0', $script], $descriptor, $pipes, $root);
    if (!is_resource($process)) {
        @unlink($script);
        fail('php-cgi startte niet');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($script);
    $stdout = is_string($stdout) ? $stdout : '';
    $stderr = is_string($stderr) ? $stderr : '';
    if ($exitCode !== 0 && strpos($stdout, 'Geen toegang') === false) {
        fail('anonieme cgi-actie exit ' . $exitCode . ' ' . $stderr . ' ' . $stdout);
    }
    assert_contains($stdout, 'Geen toegang', 'anonieme cgi-actie');
    assert_not_contains($stdout, 'SHOULD_NOT_REACH', 'anonieme cgi-actie');
    assert_not_contains($stdout, 'sqlite', 'anonieme cgi-actie');
    assert_not_contains($stdout, 'xlsx', 'anonieme cgi-actie');

    $runCgi = static function (string $body) use ($phpCgi, $root): string {
        $script = sys_get_temp_dir() . '/calculus-login-cgi-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, $body);
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([$phpCgi, '-q', '-d', 'display_errors=0', $script], $descriptor, $pipes, $root);
        if (!is_resource($process)) {
            @unlink($script);
            fail('php-cgi startte niet');
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        @unlink($script);

        return is_string($out) ? $out : '';
    };
    $loginExport = var_export($loginFile, true);
    $withSecret = $runCgi("<?php\n"
        . '$_SERVER["REMOTE_ADDR"] = "203.0.113.50";' . "\n"
        . '$_SERVER["HTTP_X_CALCULUS_SECRET"] = "server-secret";' . "\n"
        . '$GLOBALS["calculusRunnerSecret"] = "server-secret";' . "\n"
        . 'require ' . $loginExport . ";\n"
        . "echo \"REACHED\";\n");
    assert_contains($withSecret, 'REACHED', 'runnergeheim via cgi');
    assert_not_contains($withSecret, 'Geen toegang', 'runnergeheim via cgi');

    $wrongSecret = $runCgi("<?php\n"
        . '$_SERVER["REMOTE_ADDR"] = "203.0.113.50";' . "\n"
        . '$_SERVER["HTTP_X_CALCULUS_SECRET"] = "nope";' . "\n"
        . '$GLOBALS["calculusRunnerSecret"] = "server-secret";' . "\n"
        . 'require ' . $loginExport . ";\n"
        . "echo \"SHOULD_NOT_REACH\";\n");
    assert_contains($wrongSecret, 'Geen toegang', 'fout runnergeheim');
    assert_not_contains($wrongSecret, 'SHOULD_NOT_REACH', 'fout runnergeheim');

    $querySecret = $runCgi("<?php\n"
        . '$_SERVER["REMOTE_ADDR"] = "203.0.113.50";' . "\n"
        . 'unset($_SERVER["HTTP_X_CALCULUS_SECRET"]);' . "\n"
        . '$_GET["secret"] = "server-secret";' . "\n"
        . '$GLOBALS["calculusRunnerSecret"] = "server-secret";' . "\n"
        . 'require ' . $loginExport . ";\n"
        . "echo \"SHOULD_NOT_REACH\";\n");
    assert_contains($querySecret, 'Geen toegang', 'querygeheim telt niet');
    assert_not_contains($querySecret, 'SHOULD_NOT_REACH', 'querygeheim telt niet');
}

echo "OK action access\n";
