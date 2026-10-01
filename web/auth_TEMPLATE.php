<?php
/**
 * Kopieer naar web/auth.php (niet in git).
 *
 * Zelfde model als Demeter / Medusa / Mímir:
 * - $baseUrl is alleen de host
 * - elke BC-instance is een sleutel in $auth_list
 * - $environment is de default-actieve lijst (string of array)
 */

$baseUrl = 'https://kvtmd365.kvt.nl:7148';

// Default: FAT. Live alleen na expliciete keuze in de UI.
$environment = [
    'kvtmdlive_fat',
    // 'kvtmdlive_aad',
];

$auth_list = [
    'kvtmdlive_fat' => [
        'mode' => 'basic', // of 'ntlm'
        'user' => 'USERNAME',
        'pass' => 'WEB_SERVICE_ACCESS_KEY',
    ],
    // 'kvtmdlive_aad' => [
    //     'mode' => 'basic',
    //     'user' => 'USERNAME',
    //     'pass' => 'WEB_SERVICE_ACCESS_KEY',
    // ],
];

$auth = $auth_list[is_array($environment) ? ($environment[0] ?? '') : $environment] ?? null;

// Alleen ICT
$allowedUsers = [
    'tim@kvt.nl',
];

$ictUsers = $allowedUsers;

// Optioneel: Asclepius service-key voor tickets/bijlagen/reacties
// $asclepiusApiKey = '…';
// $asclepiusBase = 'https://sleutels.kvt.nl/asclepius';

// Mímir voor het lezen van bestaande projectbasislijnregels (OData).
// Zet $mimirApi én de BC-credentials hierboven naast elkaar.
// Met $mimirApi gaan die reads eerst naar Mímir. Geeft Mímir een fout
// (verbinding/timeout, non-2xx, ongeldige JSON of een foutpayload), dan
// leest Calculus dezelfde regels via de eigen OData van de gekozen environment
// en slaat Mímir voor de rest van dat PHP-verzoek over.
// Zonder $mimirApi blijft alleen die directe OData actief.
// Automation API (company-GUID, pakket apply) gaat nooit via Mímir.
// $mimirApi = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// BC company-naam voor Automation API (GUID wordt live opgezocht)
// $calculusDefaultCompany = 'KVT';
