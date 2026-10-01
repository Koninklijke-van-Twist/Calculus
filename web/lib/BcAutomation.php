<?php

require_once __DIR__ . '/MimirClient.php';

/**
 * Business Central Automation API — configuratiepakketten.
 *
 * Live apply vereist werkende credentials in auth.php en bereikbare NST.
 * Zonder dat: alleen package-bestand genereren (dry-run download).
 *
 * OData-reads (bestaande basislijnregels) gaan via Mímir als $mimirApi gezet is,
 * en vallen terug op deze klasse haar eigen OData als Mímir een fout geeft.
 * Automation API (company-GUID, pakket upload/import/apply) blijft altijd direct
 * naar BC: Mímir is een leescache en kent die schrijfacties niet.
 */
final class BcAutomation
{
    public function __construct(
        private string $baseUrl,
        private string $environment,
        private array $auth,
        private string $companyName,
    ) {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->environment = trim($environment);
        $this->companyName = trim($companyName);

        if ($this->baseUrl === '') {
            throw new RuntimeException(
                'BC baseUrl ontbreekt in auth.php. Zet bijvoorbeeld $baseUrl = \'https://kvtmd365.kvt.nl:7148\';'
            );
        }
        if ($this->environment === '') {
            throw new RuntimeException('BC-environment ontbreekt.');
        }
        if (!preg_match('#^https?://#i', $this->baseUrl)) {
            throw new RuntimeException(
                'BC baseUrl is ongeldig (verwacht http:// of https://): ' . $this->baseUrl
            );
        }
    }

    /**
     * Waar de laatste geslaagde (of laatste geprobeerde) basislijn-read vandaan kwam:
     * mimir, odata, odata-fallback, odata-environment.
     */
    private string $baselineSource = 'odata';

    /** @var callable(string): array<string, mixed>|null */
    public static $odataTransport = null;

    public static function fromGlobals(string $environment, string $companyName): self
    {
        global $baseUrl, $auth_list;
        $list = is_array($auth_list ?? null) ? $auth_list : [];
        $auth = $list[$environment] ?? null;
        if (!is_array($auth)) {
            throw new RuntimeException('Geen auth_list-entry voor environment: ' . $environment);
        }
        $base = trim((string) ($baseUrl ?? ''));
        if ($base === '') {
            throw new RuntimeException(
                'BC baseUrl ontbreekt in auth.php. Zet bijvoorbeeld $baseUrl = \'https://kvtmd365.kvt.nl:7148\';'
            );
        }
        $user = trim((string) ($auth['user'] ?? ''));
        $pass = (string) ($auth['pass'] ?? '');
        if ($user === '' || $pass === '') {
            throw new RuntimeException(
                'BC user/pass ontbreekt in auth.php voor environment: ' . $environment
            );
        }

        return new self($base, $environment, $auth, $companyName);
    }

    public function automationRoot(): string
    {
        return $this->baseUrl . '/' . rawurlencode($this->environment)
            . '/api/microsoft/automation/v2.0';
    }

    public function odataRoot(): string
    {
        return $this->baseUrl . '/' . rawurlencode($this->environment) . '/ODataV4';
    }

    /**
     * Bouwt een OData company/entity-URL met correct geëncodeerde query (geen spaties in raw URL).
     *
     * @param array<string, scalar> $query
     */
    public function companyEntityUrl(string $entitySet, array $query = []): string
    {
        $this->requireCompanyName();
        $safeCompany = str_replace("'", "''", $this->companyName);
        $companySegment = "Company('" . rawurlencode($safeCompany) . "')";
        $url = $this->odataRoot() . '/' . $companySegment . '/' . rawurlencode($entitySet);
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /** @return list<array{id:string,name:string}> */
    public function listCompanies(): array
    {
        $url = $this->automationRoot() . '/companies';
        $json = $this->requestJson('GET', $url);
        $out = [];
        foreach ($json['value'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'id' => (string) ($row['id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
            ];
        }

        return $out;
    }

    public function resolveCompanyId(): string
    {
        $this->requireCompanyName();
        foreach ($this->listCompanies() as $c) {
            if (strcasecmp($c['name'], $this->companyName) === 0) {
                return $c['id'];
            }
        }
        throw new RuntimeException('Company niet gevonden via Automation API: ' . $this->companyName);
    }

    public function baselineReadSource(): string
    {
        return $this->baselineSource;
    }

    /**
     * OData entity-set kandidaten voor projectbasislijnregels (tabel 11332917).
     * Override optioneel via $calculusBaselineODataEntities in auth.php.
     * Zelfde lijst voor Mímir en voor de eigen OData-fallback.
     *
     * @return list<string>
     */
    public function baselineEntityCandidates(): array
    {
        global $calculusBaselineODataEntities;
        if (is_array($calculusBaselineODataEntities ?? null) && $calculusBaselineODataEntities !== []) {
            $out = [];
            foreach ($calculusBaselineODataEntities as $name) {
                $name = trim((string) $name);
                if ($name !== '') {
                    $out[] = $name;
                }
            }
            if ($out !== []) {
                return $out;
            }
        }

        // Caption / RapidStart-XML-naam — alleen bruikbaar als Web Service gepubliceerd.
        return [
            'Projectbasislijnregel',
            'LVS_JobChngeOrderBudgetLne',
        ];
    }

    /**
     * Bestaande basislijnregels. Met $mimirApi eerst Mímir, anders (of na een
     * Mímir-fout) de eigen OData-route. Gooit bij falen; de dry-run vangt dat
     * af als waarschuwing zonder te blokkeren.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchExistingBaselineLines(string $jobNo): array
    {
        $this->requireCompanyName();
        $this->baselineSource = 'odata';
        if (!MimirClient::enabled()) {
            return $this->fetchExistingBaselineLinesDirect($jobNo);
        }

        if (MimirClient::circuitOpen()) {
            if (!$this->bcCredentialsUsable()) {
                $previous = MimirClient::lastError();
                if ($previous instanceof Throwable) {
                    throw $previous;
                }
                throw new RuntimeException('Mímir eerder mislukt.');
            }
            $this->baselineSource = 'odata-fallback';

            return $this->fetchExistingBaselineLinesDirect($jobNo);
        }

        $lastFailure = null;
        foreach ($this->baselineEntityCandidates() as $entity) {
            try {
                $response = $this->mimirBaselineQuery($entity, $jobNo);
            } catch (Throwable $e) {
                if (!MimirClient::isFailure($e)) {
                    throw $e;
                }
                $lastFailure = $e;
                if (!MimirClient::isEntityMiss($e)) {
                    break;
                }
                continue;
            }

            $reported = '';
            $meta = $response['meta'] ?? null;
            if (is_array($meta)) {
                $reported = trim((string) ($meta['environment'] ?? ''));
            }
            if ($reported !== '' && strcasecmp($reported, $this->environment) !== 0) {
                MimirClient::logEnvironmentMismatch($reported, $this->environment, $this->companyName, $this->mimirLogSecrets());
                $this->baselineSource = 'odata-environment';

                return $this->fetchExistingBaselineLinesDirectExplained(
                    $jobNo,
                    'Mímir antwoordde voor environment ' . $reported
                    . ' in plaats van ' . $this->environment . '.'
                );
            }

            $rows = $response['value'] ?? [];
            $this->baselineSource = 'mimir';

            return is_array($rows) ? $rows : [];
        }

        if (!$lastFailure instanceof Throwable) {
            return $this->fetchExistingBaselineLinesDirect($jobNo);
        }

        MimirClient::trip($lastFailure);
        if (!$this->bcCredentialsUsable()) {
            throw $lastFailure;
        }
        MimirClient::logFallback($lastFailure, $this->mimirLogSecrets());
        $this->baselineSource = 'odata-fallback';

        return $this->fetchExistingBaselineLinesDirectExplained(
            $jobNo,
            'Mímir: ' . $lastFailure->getMessage()
        );
    }

    /**
     * Eigen OData, zonder Mímir. Eerste entity/veld dat HTTP-succes geeft wint,
     * ook als die lijst leeg is. Job_No eerst (OData-page), daarna JobNo.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchExistingBaselineLinesDirect(string $jobNo): array
    {
        $candidates = $this->baselineEntityCandidates();
        /** @var list<array{entity:string,url:string,error:string}> $attempts */
        $attempts = [];

        foreach ($candidates as $entity) {
            foreach (['Job_No', 'JobNo'] as $jobField) {
                $url = $this->companyEntityUrl($entity, [
                    '$filter' => $jobField . " eq '" . $this->odataEscape($jobNo) . "'",
                    '$top' => 500,
                ]);
                try {
                    $json = $this->odataGetJson($url);
                    $rows = $json['value'] ?? [];

                    return is_array($rows) ? $rows : [];
                } catch (Throwable $e) {
                    $msg = $e->getMessage();
                    $attempts[] = [
                        'entity' => $entity,
                        'url' => $url,
                        'error' => $msg,
                    ];
                    // Entity zelf ontbreekt (404): tweede veldnaam heeft geen zin.
                    if (preg_match('/\b404\b/', $msg) || stripos($msg, 'does not exist') !== false) {
                        break;
                    }
                }
            }
        }

        throw new RuntimeException($this->formatOdataReadError($attempts));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchExistingBaselineLinesDirectExplained(string $jobNo, string $prefix): array
    {
        try {
            return $this->fetchExistingBaselineLinesDirect($jobNo);
        } catch (Throwable $directError) {
            throw new RuntimeException($prefix . ' Directe OData: ' . $directError->getMessage(), 0, $directError);
        }
    }

    /**
     * Korte UI-tekst; details gaan naar error_log (geen URLs/$metadata/AL in de banner).
     *
     * @param list<array{entity:string,url:string,error:string}> $attempts
     */
    private function formatOdataReadError(array $attempts): string
    {
        $entities = [];
        foreach ($attempts as $a) {
            $entities[$a['entity']] = true;
        }
        $entityList = implode(', ', array_keys($entities));
        $last = $attempts !== [] ? $attempts[array_key_last($attempts)] : null;
        $lastError = is_array($last) ? (string) $last['error'] : '';
        $lastUrl = is_array($last) ? (string) $last['url'] : '';
        $lower = strtolower($lastError);

        $detail = [
            'env=' . $this->environment,
            'company=' . $this->companyName,
            'entities=' . ($entityList !== '' ? $entityList : '(geen)'),
        ];
        if ($lastUrl !== '') {
            $detail[] = 'url=' . $lastUrl;
        }
        if ($lastError !== '') {
            $detail[] = 'error=' . $lastError;
        }
        if (preg_match('/\b404\b/', $lastError) || str_contains($lower, 'not found')) {
            $detail[] = 'hint=entity unpublished; publish Web Services for table 11332917 '
                . '(LVS_JobChngeOrderBudgetLne) or set $calculusBaselineODataEntities';
        }
        error_log('Calculus OData baseline read soft-fail: ' . implode('; ', $detail));

        if (
            str_contains($lower, 'malformed')
            || str_contains($lower, 'url rejected')
            || str_contains($lower, 'baseurl')
        ) {
            return 'Bestaande BC-regels konden niet worden gecontroleerd (BC-verbinding of configuratie). '
                . 'Voorbeeld en download gaan door.';
        }

        if (str_contains($lower, 'company') && str_contains($lower, 'does not exist')) {
            return 'Bestaande BC-regels konden niet worden gecontroleerd (BC-bedrijf niet gevonden). '
                . 'Voorbeeld en download gaan door.';
        }

        return 'Bestaande BC-regels konden niet worden gecontroleerd (OData niet beschikbaar). '
            . 'Voorbeeld en download gaan door.';
    }

    /**
     * @return array<string, mixed>
     */
    private function mimirBaselineQuery(string $entity, string $jobNo): array
    {
        return MimirClient::query($this->companyName, $entity, [
            'filter' => 'JobNo eq \'' . $this->odataEscape($jobNo) . '\'',
            'top' => 500,
            'max_age' => 600,
        ]);
    }

    /** @return array<string, mixed> */
    private function odataGetJson(string $url): array
    {
        if (is_callable(self::$odataTransport)) {
            $json = (self::$odataTransport)($url);
            if (!is_array($json)) {
                throw new RuntimeException('OData-transport gaf geen array.');
            }

            return $json;
        }

        return $this->requestJson('GET', $url);
    }

    private function bcCredentialsUsable(): bool
    {
        if (trim($this->baseUrl) === '' || trim($this->environment) === '') {
            return false;
        }
        $user = trim((string) ($this->auth['user'] ?? ''));
        if ($user === '') {
            return false;
        }
        $mode = (string) ($this->auth['mode'] ?? 'basic');
        if ($mode === '') {
            $mode = 'basic';
        }
        if ($mode !== 'basic' && $mode !== 'ntlm') {
            return false;
        }

        return array_key_exists('pass', $this->auth);
    }

    /**
     * @return list<string>
     */
    private function mimirLogSecrets(): array
    {
        $secrets = [];
        $apiKey = MimirClient::apiKey();
        if ($apiKey !== '') {
            $secrets[] = $apiKey;
        }
        $ownPass = $this->auth['pass'] ?? null;
        if (is_string($ownPass) && $ownPass !== '') {
            $secrets[] = $ownPass;
        }
        global $auth, $auth_list;
        if (isset($auth) && is_array($auth)) {
            $pass = $auth['pass'] ?? null;
            if (is_string($pass) && $pass !== '') {
                $secrets[] = $pass;
            }
        }
        if (isset($auth_list) && is_array($auth_list)) {
            foreach ($auth_list as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $pass = $entry['pass'] ?? null;
                if (is_string($pass) && $pass !== '') {
                    $secrets[] = $pass;
                }
            }
        }

        return $secrets;
    }

    /**
     * Bedrijvenlijst (Automation API) heeft nog geen gekozen bedrijf.
     * Reads en apply wel.
     */
    private function requireCompanyName(): void
    {
        if ($this->companyName === '') {
            throw new RuntimeException('BC-bedrijfsnaam ontbreekt.');
        }
    }

    /**
     * Volledige apply-flow. Gooit met letterlijke BC-fout bij falen.
     *
     * Automation API (MS docs): packageId = GUID, niet de package-code in quotes.
     * Upload: PATCH …/configurationPackages({id})/file('{code}')/content
     *
     * @return array{package_code: string, company_id: string, package_id: string, steps: list<string>, raw: array<string, mixed>}
     */
    public function applyConfigurationPackage(string $packageCode, string $xlsxPath): array
    {
        if (!is_file($xlsxPath)) {
            throw new InvalidArgumentException('Package-bestand ontbreekt.');
        }

        $companyId = $this->resolveCompanyId();
        $steps = [];
        $root = $this->automationRoot() . '/companies(' . $companyId . ')/configurationPackages';

        $existing = $this->findConfigurationPackage($root, $packageCode);
        if (is_array($existing) && trim((string) ($existing['id'] ?? '')) !== '') {
            $packageId = (string) $existing['id'];
            $steps[] = 'configurationPackage bestond al';
        } else {
            $created = $this->requestJson('POST', $root, [
                'code' => $packageCode,
                'packageName' => $packageCode,
            ]);
            $packageId = trim((string) ($created['id'] ?? ''));
            if ($packageId === '') {
                $refetched = $this->findConfigurationPackage($root, $packageCode);
                $packageId = trim((string) ($refetched['id'] ?? ''));
            }
            if ($packageId === '') {
                throw new RuntimeException('Kon configurationPackage-id (GUID) niet bepalen na aanmaken.');
            }
            $steps[] = 'configurationPackage aangemaakt';
        }

        // On-prem: file is een collection. Verwijder oude file-entity (kan corrupt/oude zip
        // bevatten) en maak opnieuw aan vóór PATCH van de content-stream.
        $pkgRoot = $root . '(' . $packageId . ')';
        $fileCollection = $pkgRoot . '/file';
        $fileEntity = $fileCollection . "('" . rawurlencode($packageCode) . "')";
        $uploadUrl = $fileEntity . '/content';

        try {
            $this->requestJson('DELETE', $fileEntity);
            $steps[] = 'oude file-entity verwijderd';
        } catch (Throwable $e) {
            $steps[] = 'file-delete: ' . $e->getMessage();
        }

        try {
            $this->requestJson('POST', $fileCollection, ['code' => $packageCode]);
            $steps[] = 'file-entity aangemaakt';
        } catch (Throwable $e) {
            $steps[] = 'file-entity (post): ' . $e->getMessage();
        }

        try {
            $this->requestBinary('PATCH', $uploadUrl, $xlsxPath, 'application/octet-stream');
            $steps[] = 'package geüpload';
        } catch (Throwable $e) {
            throw new RuntimeException('Package-upload mislukt: ' . $e->getMessage());
        }

        // Import + apply gebruiken dezelfde GUID; status pollen (async op NST)
        foreach (
            [
                'Microsoft.NAV.import' => 'importStatus',
                'Microsoft.NAV.apply' => 'applyStatus',
            ] as $action => $statusField
        ) {
            try {
                $this->requestJson('POST', $pkgRoot . '/' . $action, new stdClass());
                $steps[] = $action . ' gestart';
            } catch (Throwable $e) {
                // Dubbele start terwijl taak al loopt is soms OK
                $steps[] = $action . ' (start): ' . $e->getMessage();
            }
            $final = $this->waitForPackageField($pkgRoot, $statusField, ['Completed', 'Error'], 180);
            $steps[] = $statusField . '=' . $final;
            if (strcasecmp($final, 'Error') === 0) {
                $pkg = $this->requestJson('GET', $pkgRoot);
                $errKey = $statusField === 'importStatus' ? 'importError' : 'applyError';
                $detail = trim((string) ($pkg[$errKey] ?? ''));
                throw new RuntimeException(
                    $action . ' mislukt' . ($detail !== '' ? ': ' . $detail : ' (status Error)')
                );
            }
        }

        return [
            'package_code' => $packageCode,
            'company_id' => $companyId,
            'package_id' => $packageId,
            'steps' => $steps,
            'raw' => [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findConfigurationPackage(string $root, string $packageCode): ?array
    {
        try {
            $filter = http_build_query(
                ['$filter' => "code eq '" . $this->odataEscape($packageCode) . "'"],
                '',
                '&',
                PHP_QUERY_RFC3986
            );
            $list = $this->requestJson('GET', $root . '?' . $filter);
            $row = $list['value'][0] ?? null;

            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function waitForPackageField(
        string $pkgRoot,
        string $field,
        array $doneValues,
        int $timeoutSeconds
    ): string {
        $deadline = time() + $timeoutSeconds;
        $last = '';
        while (time() < $deadline) {
            $pkg = $this->requestJson('GET', $pkgRoot);
            $last = (string) ($pkg[$field] ?? '');
            foreach ($doneValues as $done) {
                if (strcasecmp($last, (string) $done) === 0) {
                    return $last;
                }
            }
            sleep(2);
        }

        return $last !== '' ? $last : 'Timeout';
    }

    /**
     * @param array<string, mixed>|stdClass|null $body
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, array|stdClass|null $body = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init mislukt voor URL: ' . $url);
        }
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        if (strtoupper($method) === 'DELETE') {
            $headers[] = 'If-Match: *';
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Calculus-BCClient/1.0',
        ];
        $this->applyAuth($ch);
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('BC cURL-fout: ' . $err . ' | URL: ' . $url);
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException($this->summarizeHttpError($code, (string) $raw, $url));
        }
        if (trim((string) $raw) === '') {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('BC gaf geen JSON: ' . $this->truncateBody((string) $raw) . ' | URL: ' . $url);
        }

        return $decoded;
    }

    private function summarizeHttpError(int $code, string $raw, string $url): string
    {
        $body = $this->truncateBody($raw);
        if ($body === '') {
            $body = '(lege response body)';
        }

        return 'BC HTTP ' . $code . ': ' . $body . ' | URL: ' . $url;
    }

    private function truncateBody(string $raw): string
    {
        $body = trim($raw);
        if ($body !== '' && (str_starts_with($body, '<!') || str_starts_with($body, '<html') || str_starts_with($body, '<HTML'))) {
            $body = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (strlen($body) > 400) {
            return substr($body, 0, 400) . '…';
        }

        return $body;
    }

    private function requestBinary(string $method, string $url, string $filePath, string $contentType): void
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init mislukt voor URL: ' . $url);
        }
        $data = file_get_contents($filePath);
        if ($data === false) {
            throw new RuntimeException('Kon package-bestand niet lezen.');
        }
        $headers = [
            'Content-Type: ' . $contentType,
            'If-Match: *',
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Calculus-BCClient/1.0',
        ]);
        $this->applyAuth($ch);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('BC upload cURL-fout: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('BC upload HTTP ' . $code . ': ' . $raw);
        }
    }

    /** @param \CurlHandle $ch */
    private function applyAuth($ch): void
    {
        $mode = (string) ($this->auth['mode'] ?? 'basic');
        $user = (string) ($this->auth['user'] ?? '');
        $pass = (string) ($this->auth['pass'] ?? '');
        if ($mode === 'ntlm') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
        } else {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }
        curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
    }

    private function odataEscape(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
