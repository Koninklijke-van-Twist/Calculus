<?php

declare(strict_types=1);

require __DIR__ . '/../web/lib/CompanyCatalog.php';

$failures = 0;

function check(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        echo "ok  {$message}\n";
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL {$message}\n");
}

function expect_throw(callable $fn, string $needle, string $message): void
{
    try {
        $fn();
        check(false, $message . ' (geen exception)');
    } catch (Throwable $error) {
        check(strpos($error->getMessage(), $needle) !== false, $message . ' :: ' . $error->getMessage());
    }
}

CompanyCatalog::clearMemoryCache();

$mapped = CompanyCatalog::buildMap([
    'kvtmdlive_aad' => ['HVT', 'Koninklijke van Twist', ''],
    'kvtgermanylive_aad' => ['KVT Germany', 'KVT Germany'],
    'kvtmdlive_fat' => ['KVT FAT'],
]);
check(count($mapped) === 4, 'unieke bedrijven, lege naam weg');
check($mapped[0]['name'] === 'HVT' && $mapped[0]['environment'] === 'kvtmdlive_aad', 'HVT op kvtmdlive_aad');
check($mapped[1]['name'] === 'Koninklijke van Twist', 'natuurlijke sortering Koninklijke van Twist');
check($mapped[2]['name'] === 'KVT FAT' && $mapped[2]['environment'] === 'kvtmdlive_fat', 'FAT-bedrijf blijft op fat');
check($mapped[3]['name'] === 'KVT Germany' && $mapped[3]['environment'] === 'kvtgermanylive_aad', 'Germany op eigen database');

expect_throw(static function (): void {
    CompanyCatalog::buildMap([
        'kvtmdlive_fat' => ['KVT'],
        'kvtmdlive_aad' => ['kvt'],
    ]);
}, 'KVT [kvtmdlive_aad, kvtmdlive_fat]', 'overlap hoofdletterongevoelig');

$fromMimir = CompanyCatalog::namesByEnvironmentFromMimir([
    'value' => [
        ['name' => 'Koninklijke van Twist', 'environment' => 'kvtmdlive_aad'],
        ['name' => 'KVT Germany', 'environment' => 'kvtgermanylive_aad'],
        ['name' => 'CRONUS', 'environment' => 'ergens_anders'],
        ['name' => '', 'environment' => 'kvtmdlive_aad'],
    ],
], ['kvtmdlive_aad', 'kvtgermanylive_aad']);
check(
    $fromMimir === [
        'kvtmdlive_aad' => ['Koninklijke van Twist'],
        'kvtgermanylive_aad' => ['KVT Germany'],
    ],
    'Mímir-payload filtert op actieve environments'
);
check(CompanyCatalog::namesByEnvironmentFromMimir(['value' => 'nee'], ['kvtmdlive_aad']) === [], 'ongeldige Mímir-payload');

$cache = tempnam(sys_get_temp_dir(), 'calc-co');
if ($cache === false) {
    fwrite(STDERR, "FAIL temp cache\n");
    exit(1);
}
$GLOBALS['calculusCompanyCacheFile'] = $cache;
file_put_contents($cache, json_encode([
    'fetched_at' => time(),
    'companies' => [
        ['name' => 'KVT', 'environment' => 'kvtmdlive_fat'],
        ['name' => 'KVT Germany', 'environment' => 'kvtgermanylive_aad'],
    ],
    'warnings' => [],
], JSON_UNESCAPED_UNICODE));

$GLOBALS['baseUrl'] = 'http://127.0.0.1:9';
$GLOBALS['auth_list'] = [
    'kvtmdlive_fat' => ['mode' => 'basic', 'user' => 'x', 'pass' => 'y'],
];
$GLOBALS['environment'] = ['kvtmdlive_fat'];
unset($GLOBALS['mimirApi']);

CompanyCatalog::clearMemoryCache();
$started = microtime(true);
$loaded = CompanyCatalog::load(false);
$elapsed = microtime(true) - $started;
check($elapsed < 2, 'verse cache raakt BC niet');
check($loaded['companies'][0]['name'] === 'KVT', 'cache levert KVT');

$resolved = CompanyCatalog::resolve('kvt germany');
check($resolved['name'] === 'KVT Germany', 'resolve houdt de BC-schrijfwijze');
check($resolved['environment'] === 'kvtgermanylive_aad', 'Germany impliceert kvtgermanylive_aad');

expect_throw(static function (): void {
    CompanyCatalog::resolve('   ');
}, 'Kies een BC-bedrijf', 'leeg bedrijf');

$GLOBALS['baseUrl'] = '';
expect_throw(static function (): void {
    CompanyCatalog::resolve('Bestaat niet');
}, 'Onbekend BC-bedrijf', 'onbekend bedrijf');

file_put_contents($cache, json_encode([
    'fetched_at' => time() - 7200,
    'companies' => [
        ['name' => 'KVT', 'environment' => 'kvtmdlive_fat'],
    ],
    'warnings' => [],
]));
$GLOBALS['baseUrl'] = '';
CompanyCatalog::clearMemoryCache();
$stale = CompanyCatalog::load(false);
check($stale['companies'][0]['environment'] === 'kvtmdlive_fat', 'verlopen cache blijft bruikbaar');
check(
    strpos(implode("\n", $stale['warnings']), 'cache gebruikt') !== false,
    'waarschuwing als verversen mislukt'
);

$index = file_get_contents(__DIR__ . '/../web/index.php');
check(is_string($index) && !preg_match('/name="environment"/', (string) $index), 'UI post geen environment');
check(is_string($index) && preg_match('/<select name="company"/', (string) $index) === 1, 'bedrijf is een dropdown');

@unlink($cache);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failed\n");
    exit(1);
}

echo "all passed\n";
