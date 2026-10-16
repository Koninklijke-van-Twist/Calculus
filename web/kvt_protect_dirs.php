<?php

/**
 * Zet de afscherming van server-only mappen (cache/, data/, uploads/) neer als
 * die ontbreekt. De FTP-deploy sluit deze mappen uit (geen --delete-risico),
 * dus de app schrijft .htaccess / index.html / index.php zelf. Idempotent:
 * bestaande bestanden worden niet aangeraakt.
 */
if (!function_exists('kvt_ensure_protected_dirs')) {
    function kvt_ensure_protected_dirs(?string $webRoot = null): void
    {
        $webRoot = $webRoot ?? __DIR__;
        $files = [
            '.htaccess' => "Options -Indexes\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n",
            'index.html' => '',
            'index.php' => "<?php\nhttp_response_code(403);\n",
        ];

        // analytics/: analytics.php moet bereikbaar blijven, dus alleen de
        // sqlite-bestanden (incl. -wal/-shm/-journal) en de maplijst weigeren.
        $analyticsDir = $webRoot . '/analytics';
        if (is_dir($analyticsDir)) {
            $analyticsHt = $analyticsDir . '/.htaccess';
            $deny = "Options -Indexes\n"
                . "<FilesMatch \"\\.(sqlite|sqlite3|db)(-wal|-shm|-journal)?$\">\n"
                . "    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n"
                . "    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n"
                . "</FilesMatch>\n";
            $current = is_file($analyticsHt) ? (string) @file_get_contents($analyticsHt) : null;
            if ($current === null) {
                if (is_writable($analyticsDir)) {
                    @file_put_contents($analyticsHt, $deny, LOCK_EX);
                }
            } elseif (stripos($current, 'sqlite') === false && is_writable($analyticsHt)) {
                // Bestaande regels laten staan, sqlite-weigering erbij zetten.
                @file_put_contents($analyticsHt, rtrim($current) . "\n\n# kvt_protect_dirs\n" . $deny, LOCK_EX);
            }
        }

        foreach (['cache', 'data', 'uploads'] as $top) {
            $topDir = $webRoot . '/' . $top;
            if (!is_dir($topDir)) {
                continue;
            }
            $dirs = [$topDir];
            foreach (glob($topDir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
                $dirs[] = $sub;
            }
            foreach ($dirs as $dir) {
                foreach ($files as $name => $body) {
                    $path = $dir . '/' . $name;
                    if (!is_file($path) && is_writable($dir)) {
                        @file_put_contents($path, $body, LOCK_EX);
                    }
                }
            }
        }
    }
}

// Bij het laden én aan het eind van het request: mappen die tijdens dit request
// ontstaan (cache/odata, data/..., uploads/...) krijgen zo meteen hun afscherming.
if (!defined('KVT_PROTECT_DIRS_REGISTERED')) {
    define('KVT_PROTECT_DIRS_REGISTERED', true);
    kvt_ensure_protected_dirs();
    register_shutdown_function('kvt_ensure_protected_dirs');
}
