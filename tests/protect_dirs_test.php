<?php

// kvt_protect_dirs.php: zet .htaccess/index.* neer als ze ontbreken, laat bestaande bestanden staan.
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/kvt-protect-' . bin2hex(random_bytes(4));
foreach (['cache/odata', 'cache/usersettings', 'data/user_prefs'] as $d) {
    mkdir($tmp . '/' . $d, 0777, true);
}
file_put_contents($tmp . '/data/index.html', 'bestaand');
require $root . '/web/kvt_protect_dirs.php';
// De automatische aanroep draaide op web/; de testmap draait in een subproces.
$helper = file_get_contents($root . '/web/kvt_protect_dirs.php');
$helper = str_replace('kvt_ensure_protected_dirs();', 'kvt_ensure_protected_dirs(' . var_export($tmp, true) . ');', $helper);
$script = $tmp . '/run.php';
file_put_contents($script, $helper);
shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script));
foreach (['cache', 'cache/odata', 'cache/usersettings', 'data', 'data/user_prefs'] as $d) {
    foreach (['.htaccess', 'index.html', 'index.php'] as $f) {
        if (!is_file("$tmp/$d/$f")) {
            fwrite(STDERR, "FAIL: $d/$f ontbreekt\n");
            exit(1);
        }
    }
    if (strpos((string) file_get_contents("$tmp/$d/.htaccess"), 'Require all denied') === false) {
        fwrite(STDERR, "FAIL: $d/.htaccess weigert niet\n");
        exit(1);
    }
}
if (file_get_contents($tmp . '/data/index.html') !== 'bestaand') {
    fwrite(STDERR, "FAIL: bestaand bestand overschreven\n");
    exit(1);
}

// Verse webroot: topmappen bestaan nog niet. Het schild moet er staan vóórdat
// hetzelfde verzoek sqlite of pakketten schrijft. data/packages ontstaat pas
// daarna en moet aan het eind van het verzoek ook dicht.
$fresh = sys_get_temp_dir() . '/kvt-protect-fresh-' . bin2hex(random_bytes(4));
mkdir($fresh, 0777, true);
$freshHelper = file_get_contents($root . '/web/kvt_protect_dirs.php');
$freshHelper = str_replace(
    'kvt_ensure_protected_dirs();',
    'kvt_ensure_protected_dirs(' . var_export($fresh, true) . ');' . "\n"
        . 'if (!is_file(' . var_export($fresh . '/data/.htaccess', true) . ')) {' . "\n"
        . '    fwrite(STDERR, "FAIL: data/.htaccess ontbreekt voordat het verzoek schrijft\n");' . "\n"
        . '    exit(1);' . "\n"
        . '}' . "\n"
        . 'file_put_contents(' . var_export($fresh . '/data/calculus.sqlite', true) . ', "secret");' . "\n"
        . 'mkdir(' . var_export($fresh . '/data/packages', true) . ', 0777, true);' . "\n"
        . 'file_put_contents(' . var_export($fresh . '/data/packages/pakket.xlsx', true) . ', "secret");' . "\n",
    $freshHelper
);
$freshScript = $fresh . '/run.php';
file_put_contents($freshScript, $freshHelper);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($freshScript) . ' 2>&1', $freshOut, $freshCode);
if ($freshCode !== 0) {
    fwrite(STDERR, "FAIL: verse webroot\n" . implode("\n", $freshOut) . "\n");
    exit(1);
}
foreach (['cache', 'data', 'uploads', 'data/packages'] as $d) {
    foreach (['.htaccess', 'index.html', 'index.php'] as $f) {
        if (!is_file("$fresh/$d/$f")) {
            fwrite(STDERR, "FAIL: verse map $d/$f ontbreekt\n");
            exit(1);
        }
    }
    if (strpos((string) file_get_contents("$fresh/$d/.htaccess"), 'Require all denied') === false) {
        fwrite(STDERR, "FAIL: verse map $d/.htaccess weigert niet\n");
        exit(1);
    }
}
$workflow = (string) file_get_contents($root . '/.github/workflows/deploy-ftp.yml');
if (strpos($workflow, '--include-glob') !== false || strpos($workflow, 'put -O') !== false) {
    fwrite(STDERR, "FAIL: ftp-deploy mag geen includes/put hebben (--delete wiste server-only mappen)\n");
    exit(1);
}
echo "OK protect dirs\n";
