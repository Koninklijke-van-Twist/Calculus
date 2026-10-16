<?php

// kvt_protect_dirs.php: zet .htaccess/index.* neer als ze ontbreken, laat bestaande bestanden staan.
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/kvt-protect-' . bin2hex(random_bytes(4));
foreach (['cache/odata', 'cache/usersettings', 'data/user_prefs', 'analytics'] as $d) {
    mkdir($tmp . '/' . $d, 0777, true);
}
file_put_contents($tmp . '/data/index.html', 'bestaand');
require $root . '/web/kvt_protect_dirs.php';
// De automatische aanroep draaide op web/; nu expliciet op de testmap (static guard omzeilen via subproces).
$helper = file_get_contents($root . '/web/kvt_protect_dirs.php');
$helper = str_replace("register_shutdown_function('kvt_ensure_protected_dirs');", '', str_replace('    kvt_ensure_protected_dirs();', '    kvt_ensure_protected_dirs(' . var_export($tmp, true) . ');', $helper));
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
$aht = (string) @file_get_contents($tmp . '/analytics/.htaccess');
if (strpos($aht, 'sqlite') === false || strpos($aht, 'FilesMatch') === false || strpos($aht, 'Require all denied') === false) {
    fwrite(STDERR, "FAIL: analytics/.htaccess weigert sqlite niet\n");
    exit(1);
}
$workflow = (string) file_get_contents($root . '/.github/workflows/deploy-ftp.yml');
if (strpos($workflow, '--include-glob') !== false || strpos($workflow, 'put -O') !== false) {
    fwrite(STDERR, "FAIL: ftp-deploy mag geen includes/put hebben (--delete wiste server-only mappen)\n");
    exit(1);
}
echo "OK protect dirs\n";
