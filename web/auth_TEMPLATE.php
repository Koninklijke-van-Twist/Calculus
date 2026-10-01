<?php
/**
 * Kopieer naar web/auth.php (niet in git).
 *
 * Zelfde model als Demeter / Medusa / Mímir:
 * - $baseUrl is alleen de host
 * - elke BC-instance is een sleutel in $auth_list
 * - $environment is de lijst databases waarvan bedrijven in de dropdown komen
 *
 * De UI kiest geen environment. Een bedrijf wijst naar precies één database
 * uit deze lijst (docs/COMPANIES.md). Dezelfde bedrijfsnaam in twee actieve
 * databases wordt geweigerd — zet FAT en live niet allebei aan als ze dezelfde
 * bedrijfsnamen delen.
 */

$baseUrl = 'https://kvtmd365.kvt.nl:7148';

// Actieve databases voor de bedrijven-dropdown. Geen UI-keuze.
$environment = [
    'kvtmdlive_fat',
    // 'kvtmdlive_aad',       // KVT + HVT
    // 'kvtgermanylive_aad',  // KVT Germany
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
    // 'kvtgermanylive_aad' => [
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

// Mímir ($mimirApi), naast de BC-credentials hierboven:
// - bedrijvenlijst via GET /companies.php (name + environment). Zonder sleutel
//   ontdekt Calculus de bedrijven via de Automation API.
// - bestaande projectbasislijnregels (OData) via query.php. Met $mimirApi gaan
//   die reads eerst naar Mímir. Geeft Mímir een fout (verbinding/timeout,
//   non-2xx, ongeldige JSON of een foutpayload), dan leest Calculus dezelfde
//   regels via de eigen OData van de environment van het gekozen bedrijf en
//   slaat Mímir voor de rest van dat PHP-verzoek over.
// Zonder $mimirApi blijft alleen die directe OData actief.
// Automation API (company-GUID, pakket apply) gaat nooit via Mímir.
// $mimirApi = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// Voorkeursbedrijf in de dropdown. Moet in de ontdekte lijst staan.
// $calculusDefaultCompany = 'KVT';
