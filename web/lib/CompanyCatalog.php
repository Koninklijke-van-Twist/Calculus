<?php

declare(strict_types=1);

require_once __DIR__ . '/BcAutomation.php';

/**
 * BC-bedrijven over de actieve environments, met één bedrijf → één environment.
 *
 * Zelfde regel als Mímir/Penates (auth_get_environment_for_company): de UI kiest
 * een bedrijf, niet een database. De lijst komt uit Mímir GET /companies.php
 * wanneer $mimirApi gezet is, anders uit de Automation API per environment.
 */
final class CompanyCatalog
{
    private const CACHE_TTL_SECONDS = 3600;

    /** @var array{fetched_at:int,companies:list<array{name:string,environment:string}>,warnings:list<string>}|null */
    private static $memo = null;

    public static function clearMemoryCache(): void
    {
        self::$memo = null;
    }

    public static function cacheFile(): string
    {
        $override = $GLOBALS['calculusCompanyCacheFile'] ?? null;
        if (is_string($override) && $override !== '') {
            return $override;
        }

        return dirname(__DIR__) . '/cache/bc-companies.json';
    }

    /**
     * Actieve environments: $environment gefilterd op sleutels die in $auth_list staan.
     *
     * @return list<string>
     */
    public static function activeEnvironments(): array
    {
        global $auth_list, $environment;

        $known = is_array($auth_list ?? null) ? array_keys($auth_list) : [];
        $configured = [];
        if (is_array($environment ?? null)) {
            $configured = array_values(array_filter(array_map('strval', $environment), static function (string $item): bool {
                return trim($item) !== '';
            }));
        } elseif (is_string($environment ?? null) && trim($environment) !== '') {
            $configured = [trim($environment)];
        }

        if ($configured !== []) {
            $knownMap = array_fill_keys(array_map('strval', $known), true);
            $configured = array_values(array_filter($configured, static function (string $item) use ($knownMap): bool {
                return isset($knownMap[$item]);
            }));
        }

        if ($configured === [] && $known !== []) {
            return array_map('strval', $known);
        }

        return $configured;
    }

    /**
     * @param array<string, list<string>> $namesByEnvironment
     * @return list<array{name:string,environment:string}>
     */
    public static function buildMap(array $namesByEnvironment): array
    {
        $companyToEnvironment = [];
        $duplicates = [];

        foreach ($namesByEnvironment as $environment => $names) {
            $environmentName = trim((string) $environment);
            if ($environmentName === '' || !is_array($names)) {
                continue;
            }

            $seenHere = [];
            foreach ($names as $name) {
                $companyName = trim((string) $name);
                if ($companyName === '') {
                    continue;
                }
                $key = strtolower($companyName);
                if (isset($seenHere[$key])) {
                    continue;
                }
                $seenHere[$key] = true;

                if (!isset($companyToEnvironment[$key])) {
                    $companyToEnvironment[$key] = [
                        'name' => $companyName,
                        'environment' => $environmentName,
                    ];
                    continue;
                }

                $existingEnvironment = (string) $companyToEnvironment[$key]['environment'];
                if ($existingEnvironment === $environmentName) {
                    continue;
                }

                if (!isset($duplicates[$key])) {
                    $duplicates[$key] = [
                        'name' => (string) $companyToEnvironment[$key]['name'],
                        'environments' => [$existingEnvironment],
                    ];
                }
                $duplicates[$key]['environments'][] = $environmentName;
            }
        }

        if ($duplicates !== []) {
            $messages = [];
            foreach ($duplicates as $duplicate) {
                $envs = array_values(array_unique($duplicate['environments']));
                sort($envs, SORT_NATURAL | SORT_FLAG_CASE);
                $messages[] = $duplicate['name'] . ' [' . implode(', ', $envs) . ']';
            }
            sort($messages, SORT_NATURAL | SORT_FLAG_CASE);

            throw new RuntimeException(
                'Bedrijfsnaam-overlap tussen actieve environments. Kies unieke bedrijfsnamen per environment. Conflicten: '
                . implode('; ', $messages)
            );
        }

        $companies = array_values($companyToEnvironment);
        usort($companies, static function (array $a, array $b): int {
            return strnatcasecmp($a['name'], $b['name']);
        });

        return $companies;
    }

    /**
     * Mímir GET /companies.php → namen per environment, alleen voor actieve databases.
     *
     * @param array<string, mixed> $payload
     * @param list<string> $activeEnvironments
     * @return array<string, list<string>>
     */
    public static function namesByEnvironmentFromMimir(array $payload, array $activeEnvironments): array
    {
        $active = array_fill_keys($activeEnvironments, true);
        $byEnvironment = [];
        $rows = $payload['value'] ?? null;
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $environment = trim((string) ($row['environment'] ?? ''));
            if ($name === '' || $environment === '' || !isset($active[$environment])) {
                continue;
            }
            $byEnvironment[$environment][] = $name;
        }

        return $byEnvironment;
    }

    /**
     * @return array{fetched_at:int,companies:list<array{name:string,environment:string}>,warnings:list<string>}
     */
    public static function load(bool $refresh = false): array
    {
        if (!$refresh && is_array(self::$memo)) {
            return self::$memo;
        }

        $cached = self::readCache();
        $freshEnough = is_array($cached)
            && (time() - (int) $cached['fetched_at']) < self::CACHE_TTL_SECONDS;
        if (!$refresh && $freshEnough) {
            self::$memo = $cached;

            return $cached;
        }

        try {
            $loaded = self::discover();
            self::writeCache($loaded);
            self::$memo = $loaded;

            return $loaded;
        } catch (Throwable $error) {
            if (is_array($cached) && $cached['companies'] !== []) {
                $cached['warnings'][] = 'Actuele bedrijvenlijst niet bereikbaar; cache gebruikt. ' . $error->getMessage();
                self::$memo = $cached;

                return $cached;
            }

            throw $error;
        }
    }

    /**
     * @return array{name:string,environment:string}
     */
    public static function resolve(string $company): array
    {
        $companyName = trim($company);
        if ($companyName === '') {
            throw new InvalidArgumentException('Kies een BC-bedrijf.');
        }

        $found = self::findIn($companyName, self::load(false)['companies']);
        if ($found !== null) {
            return $found;
        }

        $found = self::findIn($companyName, self::load(true)['companies']);
        if ($found !== null) {
            return $found;
        }

        throw new InvalidArgumentException('Onbekend BC-bedrijf: ' . $companyName);
    }

    /**
     * @return array{fetched_at:int,companies:list<array{name:string,environment:string}>,warnings:list<string>}
     */
    private static function discover(): array
    {
        $environments = self::activeEnvironments();
        if ($environments === []) {
            throw new RuntimeException('Geen actieve environments geconfigureerd.');
        }

        $warnings = [];
        $namesByEnvironment = null;
        $mimirFailure = self::mimirFailureReason();
        if ($mimirFailure === null) {
            try {
                $fromMimir = self::namesByEnvironmentFromMimir(self::fetchMimirCompanies(), $environments);
                if ($fromMimir !== []) {
                    $namesByEnvironment = $fromMimir;
                } else {
                    $warnings[] = 'Mímir gaf geen bedrijven voor de actieve environments. Lijst komt uit de Automation API.';
                }
            } catch (Throwable $error) {
                $warnings[] = 'Mímir-bedrijvenlijst mislukt (' . $error->getMessage() . '). Lijst komt uit de Automation API.';
            }
        }

        if ($namesByEnvironment === null) {
            $automation = self::namesViaAutomation($environments);
            $namesByEnvironment = $automation['by_environment'];
            foreach ($automation['errors'] as $error) {
                $warnings[] = $error;
            }
        }

        return [
            'fetched_at' => time(),
            'companies' => self::buildMap($namesByEnvironment),
            'warnings' => $warnings,
        ];
    }

    private static function mimirFailureReason(): ?string
    {
        global $mimirApi;
        $key = trim((string) ($mimirApi ?? ''));
        if ($key === '') {
            return 'geen $mimirApi';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fetchMimirCompanies(): array
    {
        global $mimirApi, $mimirBase;
        $key = trim((string) ($mimirApi ?? ''));
        $base = trim((string) ($mimirBase ?? 'https://sleutels.kvt.nl/mimir/api'));
        if ($base === '') {
            $base = 'https://sleutels.kvt.nl/mimir/api';
        }
        $url = rtrim($base, '/') . '/companies.php';

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init mislukt');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-API-Key: ' . $key,
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Calculus-BCClient/1.0',
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Mímir cURL-fout: ' . $err);
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Mímir gaf geen JSON (HTTP ' . $code . ').');
        }
        if ($code < 200 || $code >= 300) {
            $message = trim((string) ($decoded['error'] ?? ''));
            throw new RuntimeException('Mímir HTTP ' . $code . ($message !== '' ? ': ' . $message : ''));
        }

        return $decoded;
    }

    /**
     * @param list<string> $environments
     * @return array{by_environment: array<string, list<string>>, errors: list<string>}
     */
    private static function namesViaAutomation(array $environments): array
    {
        $byEnvironment = [];
        $errors = [];
        foreach ($environments as $environment) {
            try {
                $client = BcAutomation::fromGlobals($environment, '');
                $names = [];
                foreach ($client->listCompanies() as $company) {
                    $name = trim((string) ($company['name'] ?? ''));
                    if ($name !== '') {
                        $names[] = $name;
                    }
                }
                if ($names === []) {
                    $errors[] = $environment . ': geen bedrijven via Automation API';
                    continue;
                }
                $byEnvironment[$environment] = $names;
            } catch (Throwable $error) {
                $errors[] = $environment . ': ' . $error->getMessage();
            }
        }

        if ($byEnvironment === []) {
            $detail = $errors !== [] ? implode(' | ', $errors) : 'geen antwoord';
            throw new RuntimeException(
                'Bedrijven ophalen mislukt voor alle actieve environments. ' . $detail
            );
        }

        return [
            'by_environment' => $byEnvironment,
            'errors' => $errors,
        ];
    }

    /**
     * @param list<array{name:string,environment:string}> $companies
     * @return array{name:string,environment:string}|null
     */
    private static function findIn(string $company, array $companies): ?array
    {
        foreach ($companies as $row) {
            if (strcasecmp($row['name'], $company) === 0) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array{fetched_at:int,companies:list<array{name:string,environment:string}>,warnings:list<string>}|null
     */
    private static function readCache(): ?array
    {
        $path = self::cacheFile();
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['companies'] ?? null)) {
            return null;
        }

        $companies = [];
        foreach ($decoded['companies'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $environment = trim((string) ($row['environment'] ?? ''));
            if ($name === '' || $environment === '') {
                continue;
            }
            $companies[] = [
                'name' => $name,
                'environment' => $environment,
            ];
        }
        if ($companies === []) {
            return null;
        }

        $warnings = [];
        foreach ($decoded['warnings'] ?? [] as $warning) {
            $text = trim((string) $warning);
            if ($text !== '') {
                $warnings[] = $text;
            }
        }

        return [
            'fetched_at' => (int) ($decoded['fetched_at'] ?? 0),
            'companies' => $companies,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array{fetched_at:int,companies:list<array{name:string,environment:string}>,warnings:list<string>} $payload
     */
    private static function writeCache(array $payload): void
    {
        $path = self::cacheFile();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return;
        }
        file_put_contents($path, $json, LOCK_EX);
    }
}
