<?php

declare(strict_types=1);

/**
 * OData-reads: Mímir als $mimirApi gezet is, eigen OData als Mímir een fout geeft.
 * Mímir wijst in deze test nooit naar het netwerk; de BC-fetch is een stub.
 */

require __DIR__ . '/../web/lib/BcAutomation.php';

$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$auth = [
    'mode' => 'basic',
    'user' => 'svc',
    'pass' => 's3cret-pass',
];
$auth_list = [
    'kvtmdlive_fat' => $auth,
];

$logFile = tempnam(sys_get_temp_dir(), 'calc-mimir-');
if (!is_string($logFile) || $logFile === '') {
    fwrite(STDERR, "kon geen logbestand maken\n");
    exit(1);
}
ini_set('log_errors', '1');
ini_set('error_log', $logFile);

/** @var list<array{method: string, url: string, body: ?string, headers: list<string>}> $mimirCalls */
$mimirCalls = [];
/** @var list<string> $odataUrls */
$odataUrls = [];
/** @var array{status: int, body: string}|Throwable $mimirScript */
$mimirScript = ['status' => 200, 'body' => '{"value":[]}'];

MimirClient::$transport = static function (string $method, string $url, ?string $body, array $headers) use (&$mimirCalls, &$mimirScript): array {
    $mimirCalls[] = [
        'method' => $method,
        'url' => $url,
        'body' => $body,
        'headers' => $headers,
    ];
    $script = $mimirScript;
    if ($script instanceof Throwable) {
        throw $script;
    }
    if (is_callable($script)) {
        $script = $script();
    }
    if (!is_array($script)) {
        mimir_fail('Mímir-stub gaf geen antwoord');
    }

    return $script;
};

BcAutomation::$odataTransport = static function (string $url) use (&$odataUrls): array {
    $odataUrls[] = $url;

    return ['value' => [['Quantity' => 2, 'UnitCost' => 3.5, 'JobNo' => 'PRJ1']]];
};

function mimir_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function mimir_reset_calls(): void
{
    global $mimirCalls, $odataUrls;
    $mimirCalls = [];
    $odataUrls = [];
    MimirClient::resetCircuit();
}

function mimir_log(): string
{
    global $logFile;
    $raw = file_get_contents($logFile);

    return is_string($raw) ? $raw : '';
}

function mimir_bc(?array $authOverride = null): BcAutomation
{
    global $auth;

    return new BcAutomation(
        'https://bc.example',
        'kvtmdlive_fat',
        $authOverride ?? $auth,
        'KVT'
    );
}

/**
 * @param list<array{status: int, body: string}> $steps
 */
function mimir_script_steps(array $steps): void
{
    global $mimirScript, $mimirCalls;
    $mimirScript = static function () use ($steps, &$mimirCalls): array {
        $index = count($mimirCalls) - 1;
        if (!isset($steps[$index])) {
            mimir_fail('onverwachte extra Mímir-call');
        }

        return $steps[$index];
    };
}

if (MimirClient::connectTimeoutSeconds() !== 10) {
    mimir_fail('connect-timeout moet 10s zijn');
}
if (MimirClient::timeoutSecondsForSapi('cli') !== 600) {
    mimir_fail('CLI-timeout moet 600s zijn');
}
if (MimirClient::timeoutSecondsForSapi('fpm-fcgi') !== 90 || MimirClient::timeoutSecondsForSapi('apache2handler') !== 90) {
    mimir_fail('web-timeout moet 90s zijn');
}
if (PHP_SAPI === 'cli' && MimirClient::timeoutSeconds() !== 600) {
    mimir_fail('huidige SAPI-timeout klopt niet');
}

if (mimir_bc()->baselineEntityCandidates() !== ['JobBaselineLines']) {
    mimir_fail('standaard entity-set moet JobBaselineLines zijn');
}

// Zonder key: alleen eigen OData, circuit dicht, Mímir niet aangeroepen.
mimir_reset_calls();
$mimirApi = '';
$plain = mimir_bc()->fetchExistingBaselineLines('PRJ1');
if ($mimirCalls !== [] || count($odataUrls) !== 1) {
    mimir_fail('lege $mimirApi moet alleen eigen OData gebruiken');
}
if ($plain[0]['Quantity'] !== 2) {
    mimir_fail('directe OData-rijen kwamen niet terug');
}
if (MimirClient::circuitOpen()) {
    mimir_fail('circuit mag niet open zonder Mímir');
}
$expectedDirect = "https://bc.example/kvtmdlive_fat/ODataV4/Company('KVT')/JobBaselineLines?%24filter=Job_No%20eq%20%27PRJ1%27&%24top=500";
if ($odataUrls[0] !== $expectedDirect) {
    mimir_fail('eigen OData-URL wijkt af: ' . $odataUrls[0]);
}

// Mímir-succes voor de gekozen environment: geen BC-call.
mimir_reset_calls();
$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$mimirScript = [
    'status' => 200,
    'body' => json_encode([
        'value' => [['Quantity' => 4, 'UnitCost' => 5, 'JobNo' => 'PRJ9']],
        'meta' => ['environment' => 'kvtmdlive_fat'],
    ], JSON_UNESCAPED_UNICODE),
];
$bc = mimir_bc();
$rows = $bc->fetchExistingBaselineLines('PRJ9');
if ($odataUrls !== []) {
    mimir_fail('geslaagde Mímir-read mag BC niet aanroepen');
}
if (count($mimirCalls) !== 1) {
    mimir_fail('verwachtte één Mímir-call, kreeg ' . count($mimirCalls));
}
if ($bc->baselineReadSource() !== 'mimir' || ($rows[0]['Quantity'] ?? null) !== 4) {
    mimir_fail('Mímir-rijen of bron kloppen niet');
}
if (MimirClient::circuitOpen()) {
    mimir_fail('succes mag het circuit niet openen');
}
$call = $mimirCalls[0];
if ($call['method'] !== 'POST' || substr($call['url'], -9) !== 'query.php') {
    mimir_fail('Mímir-URL klopt niet: ' . $call['url']);
}
if (strpos($call['url'], 'mimir_test_key_should_not_leak') !== false) {
    mimir_fail('API-sleutel lekt in de URL');
}
$joinedHeaders = implode("\n", $call['headers']);
if (strpos($joinedHeaders, 'Authorization: Bearer mimir_test_key_should_not_leak') === false
    || strpos($joinedHeaders, 'X-API-Key: mimir_test_key_should_not_leak') === false) {
    mimir_fail('Mímir-headers missen de sleutel');
}
$posted = json_decode((string) $call['body'], true);
if (!is_array($posted)
    || ($posted['company'] ?? null) !== 'KVT'
    || ($posted['table'] ?? null) !== 'JobBaselineLines'
    || ($posted['filter'] ?? null) !== "Job_No eq 'PRJ9'"
    || ($posted['top'] ?? null) !== 500
    || ($posted['max_age'] ?? null) !== 600
    || array_key_exists('select', $posted)) {
    mimir_fail('Mímir-body klopt niet: ' . (string) $call['body']);
}

// Leeg value is een geslaagde read.
mimir_reset_calls();
$mimirScript = ['status' => 200, 'body' => '{"value":[],"meta":{"environment":"kvtmdlive_fat"}}'];
$emptyBc = mimir_bc();
$emptyRows = $emptyBc->fetchExistingBaselineLines('PRJ1');
if ($emptyRows !== [] || $emptyBc->baselineReadSource() !== 'mimir' || $odataUrls !== []) {
    mimir_fail('lege Mímir-lijst moet gelden als succes');
}

// 404 op een geconfigureerde naam, succes op JobBaselineLines: nog steeds Mímir, circuit dicht.
mimir_reset_calls();
$calculusBaselineODataEntities = ['MissingBaseline', 'JobBaselineLines'];
mimir_script_steps([
    ['status' => 404, 'body' => '{"error":"unknown entity"}'],
    [
        'status' => 200,
        'body' => '{"value":[{"Quantity":9,"UnitCost":1}],"meta":{"environment":"KvtMdLive_FAT"}}',
    ],
]);
$second = mimir_bc();
$secondRows = $second->fetchExistingBaselineLines('PRJ1');
unset($calculusBaselineODataEntities);
if (count($mimirCalls) !== 2 || $odataUrls !== [] || MimirClient::circuitOpen()) {
    mimir_fail('404 op de eerste entity moet de tweede nog via Mímir proberen');
}
if ($second->baselineReadSource() !== 'mimir' || ($secondRows[0]['Quantity'] ?? null) !== 9) {
    mimir_fail('tweede entity kwam niet uit Mímir');
}
$secondBody = json_decode((string) $mimirCalls[1]['body'], true);
if (!is_array($secondBody)
    || ($secondBody['table'] ?? null) !== 'JobBaselineLines'
    || ($secondBody['filter'] ?? null) !== "Job_No eq 'PRJ1'") {
    mimir_fail('tweede kandidaat heeft de verkeerde tabel of filter');
}

// Transportfout: meteen fallback, tweede entity niet via Mímir, circuit open.
mimir_reset_calls();
$mimirScript = new RuntimeException('Mímir cURL error: connection refused');
$fallback = mimir_bc();
$fallbackRows = $fallback->fetchExistingBaselineLines('PRJ1');
if (count($mimirCalls) !== 1 || count($odataUrls) !== 1) {
    mimir_fail('cURL-fout moet meteen naar eigen OData');
}
if (strpos($odataUrls[0], '/JobBaselineLines?') === false || strpos($odataUrls[0], 'Job_No') === false || strpos($odataUrls[0], 'JobNo') !== false) {
    mimir_fail('fallback-OData moet JobBaselineLines met Job_No zijn: ' . $odataUrls[0]);
}
if (!$fallbackRows || $fallback->baselineReadSource() !== 'odata-fallback' || !MimirClient::circuitOpen()) {
    mimir_fail('fallback-bron of circuit klopt niet na cURL-fout');
}
$logged = mimir_log();
if (strpos($logged, 'Mímir failed, falling back to direct OData') === false) {
    mimir_fail('fallback werd niet gelogd');
}
if (strpos($logged, 'mimir_test_key_should_not_leak') !== false || strpos($logged, 's3cret-pass') !== false) {
    mimir_fail('log bevat een geheim');
}
$callsAfterOpen = count($mimirCalls);
$odataAfterOpen = count($odataUrls);
mimir_bc()->fetchExistingBaselineLines('PRJ2');
if (count($mimirCalls) !== $callsAfterOpen) {
    mimir_fail('open circuit moet Mímir overslaan');
}
if (count($odataUrls) !== $odataAfterOpen + 1 || strpos($odataUrls[$odataAfterOpen], 'PRJ2') === false) {
    mimir_fail('open circuit moet de volgende read via eigen OData doen');
}

// HTTP 500, ongeldige JSON en foutpayload zijn ook fallback-fouten.
foreach ([
    ['status' => 500, 'body' => 'down'],
    ['status' => 200, 'body' => 'geen-json'],
    ['status' => 200, 'body' => '{"error":"kapot mimir_test_key_should_not_leak en s3cret-pass"}'],
] as $failure) {
    mimir_reset_calls();
    $mimirScript = $failure;
    $via = mimir_bc();
    $via->fetchExistingBaselineLines('PRJ1');
    if (count($odataUrls) !== 1 || $via->baselineReadSource() !== 'odata-fallback' || !MimirClient::circuitOpen()) {
        mimir_fail('Mímir-fout viel niet terug: ' . json_encode($failure));
    }
}
if (strpos(mimir_log(), 'mimir_test_key_should_not_leak') !== false || strpos(mimir_log(), 's3cret-pass') !== false) {
    mimir_fail('foutpayload lekte een geheim in de log');
}

// JobBaselineLines 404: meteen circuit en eigen OData op dezelfde entity en Job_No.
mimir_reset_calls();
$mimirScript = ['status' => 404, 'body' => '{"error":"unknown entity JobBaselineLines"}'];
$both = mimir_bc();
$both->fetchExistingBaselineLines('PRJ1');
if (count($mimirCalls) !== 1 || count($odataUrls) !== 1 || !MimirClient::circuitOpen()) {
    mimir_fail('404 op JobBaselineLines moet daarna naar eigen OData');
}
$missBody = json_decode((string) $mimirCalls[0]['body'], true);
if (!is_array($missBody)
    || ($missBody['table'] ?? null) !== 'JobBaselineLines'
    || ($missBody['filter'] ?? null) !== "Job_No eq 'PRJ1'") {
    mimir_fail('404-call gebruikte niet JobBaselineLines/Job_No');
}
if (strpos($odataUrls[0], '/JobBaselineLines?') === false || strpos($odataUrls[0], 'Job_No') === false) {
    mimir_fail('OData na 404 wijkt af: ' . $odataUrls[0]);
}
if ($both->baselineReadSource() !== 'odata-fallback') {
    mimir_fail('bron na 404 moet odata-fallback zijn');
}

// Ander environment: eigen OData, circuit blijft dicht.
mimir_reset_calls();
$mimirScript = [
    'status' => 200,
    'body' => '{"value":[{"Quantity":1,"UnitCost":1}],"meta":{"environment":"kvtmdlive_aad"}}',
];
$mismatch = mimir_bc();
$mismatchRows = $mismatch->fetchExistingBaselineLines('PRJ1');
if ($mismatch->baselineReadSource() !== 'odata-environment' || count($odataUrls) !== 1 || MimirClient::circuitOpen()) {
    mimir_fail('environment-afwijking moet eigen OData gebruiken zonder circuit');
}
if (($mismatchRows[0]['Quantity'] ?? null) !== 2) {
    mimir_fail('bij environment-afwijking moeten de OData-rijen terugkomen');
}
if (strpos(mimir_log(), 'kvtmdlive_aad') === false) {
    mimir_fail('environment-afwijking werd niet gelogd');
}
$beforeRetry = count($mimirCalls);
mimir_bc()->fetchExistingBaselineLines('PRJ8');
if (count($mimirCalls) !== $beforeRetry + 1) {
    mimir_fail('environment-afwijking mag Mímir daarna niet overslaan');
}

// Geen bruikbare BC-credentials: oorspronkelijke Mímir-fout, geen OData.
mimir_reset_calls();
$mimirScript = new RuntimeException('Mímir cURL error: timeout');
$unusable = mimir_bc(['mode' => 'basic', 'user' => '', 'pass' => 's3cret-pass']);
try {
    $unusable->fetchExistingBaselineLines('PRJ1');
    mimir_fail('ontbrekende BC-user moet de Mímir-fout doorgeven');
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'Mímir cURL error: timeout') {
        mimir_fail('verkeerde fout zonder BC-credentials: ' . $e->getMessage());
    }
}
if ($odataUrls !== [] || !MimirClient::circuitOpen()) {
    mimir_fail('zonder BC-credentials mag er geen OData-call zijn');
}

// Fout die geen Mímir-fout is: niet fallbacken, circuit dicht.
mimir_reset_calls();
$mimirScript = new RuntimeException('lokale fout zonder prefix');
try {
    mimir_bc()->fetchExistingBaselineLines('PRJ1');
    mimir_fail('niet-Mímir-fout moet doorgegeven worden');
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'lokale fout zonder prefix') {
        mimir_fail('onverwachte fout: ' . $e->getMessage());
    }
}
if ($odataUrls !== [] || MimirClient::circuitOpen()) {
    mimir_fail('een fout buiten Mímir mag het circuit niet openen en geen fallback doen');
}

// Eigen OData faalt ook: beide fouten in het bericht.
mimir_reset_calls();
$mimirScript = ['status' => 503, 'body' => 'bezet'];
BcAutomation::$odataTransport = static function (string $url) use (&$odataUrls): array {
    $odataUrls[] = $url;
    throw new RuntimeException('BC HTTP 501: entity ontbreekt');
};
try {
    mimir_bc()->fetchExistingBaselineLines('PRJ1');
    mimir_fail('dubbele fout moet gooien');
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    if (strpos($message, 'Mímir HTTP 503') === false || strpos($message, 'Directe OData:') === false || strpos($message, 'OData niet beschikbaar') === false) {
        mimir_fail('samengevoegde fout mist een kant: ' . $message);
    }
    if (strpos(mimir_log(), 'BC HTTP 501') === false) {
        mimir_fail('OData-detail staat niet in de log');
    }
}

fwrite(STDOUT, "ok\n");
