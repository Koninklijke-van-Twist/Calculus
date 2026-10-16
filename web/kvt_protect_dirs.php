<?php

/**
 * Zet de afscherming van server-only mappen (cache/, data/, uploads/) neer als
 * die ontbreekt. De FTP-deploy sluit deze mappen uit (geen --delete-risico),
 * dus de app schrijft .htaccess / index.html / index.php zelf. Ontbreekt een
 * topmap nog, dan maken we die eerst aan: hetzelfde verzoek schrijft daarna
 * sqlite, cache, uploads en pakketten, en die mogen niet open blijven liggen
 * tot een volgende PHP-hit. Aan het eind van het verzoek volgt een tweede
 * ronde voor submappen die pas later ontstaan. Idempotent: bestaande
 * bestanden worden niet aangeraakt.
 */
if (!function_exists('kvt_ensure_protected_dirs')) {
    function kvt_ensure_protected_dirs(?string $webRoot = null): void
    {
        static $shutdownRegistered = false;

        $webRoot = $webRoot ?? __DIR__;
        if (!$shutdownRegistered) {
            $shutdownRegistered = true;
            register_shutdown_function(static function () use ($webRoot): void {
                kvt_ensure_protected_dirs($webRoot);
            });
        }

        $files = [
            '.htaccess' => "Options -Indexes\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n",
            'index.html' => '',
            'index.php' => "<?php\nhttp_response_code(403);\n",
        ];

        foreach (['cache', 'data', 'uploads'] as $top) {
            $topDir = $webRoot . '/' . $top;
            if (!is_dir($topDir) && !@mkdir($topDir, 0775, true) && !is_dir($topDir)) {
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

kvt_ensure_protected_dirs();
