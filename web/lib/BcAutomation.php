<?php

/**
 * Business Central Automation API — configuratiepakketten.
 *
 * Live apply vereist werkende credentials in auth.php en bereikbare NST.
 * Zonder dat: alleen package-bestand genereren (dry-run download).
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
        if ($this->companyName === '') {
            throw new RuntimeException('BC-bedrijfsnaam ontbreekt.');
        }
        if (!preg_match('#^https?://#i', $this->baseUrl)) {
            throw new RuntimeException(
                'BC baseUrl is ongeldig (verwacht http:// of https://): ' . $this->baseUrl
            );
        }
    }

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
        foreach ($this->listCompanies() as $c) {
            if (strcasecmp($c['name'], $this->companyName) === 0) {
                return $c['id'];
            }
        }
        throw new RuntimeException('Company niet gevonden via Automation API: ' . $this->companyName);
    }

    /**
     * OData entity-set kandidaten voor projectbasislijnregels (tabel 11332917).
     * Override optioneel via $calculusBaselineODataEntities in auth.php.
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
     * Probeert bestaande basislijnregels te lezen via OData (als gepubliceerd).
     * Gooit bij falen; caller (dry-run) vangt af als waarschuwing zonder te blokkeren.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchExistingBaselineLines(string $jobNo): array
    {
        $candidates = $this->baselineEntityCandidates();
        /** @var list<array{entity:string,url:string,error:string}> $attempts */
        $attempts = [];

        foreach ($candidates as $entity) {
            // OData-pages gebruiken doorgaans Job_No (zoals Demeter); RapidStart-XML gebruikt JobNo.
            foreach (['Job_No', 'JobNo'] as $jobField) {
                $url = $this->companyEntityUrl($entity, [
                    '$filter' => $jobField . " eq '" . $this->odataEscape($jobNo) . "'",
                    '$top' => 500,
                ]);
                try {
                    $json = $this->requestJson('GET', $url);
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
     * Volledige apply-flow. Gooit met letterlijke BC-fout bij falen.
     *
     * @return array{package_code: string, company_id: string, steps: list<string>, raw: array<string, mixed>}
     */
    public function applyConfigurationPackage(string $packageCode, string $xlsxPath): array
    {
        if (!is_file($xlsxPath)) {
            throw new InvalidArgumentException('Package-bestand ontbreekt.');
        }

        $companyId = $this->resolveCompanyId();
        $steps = [];
        $root = $this->automationRoot() . '/companies(' . $companyId . ')/configurationPackages';

        // Upsert package header
        $existing = null;
        try {
            $filter = http_build_query(
                ['$filter' => "code eq '" . $this->odataEscape($packageCode) . "'"],
                '',
                '&',
                PHP_QUERY_RFC3986
            );
            $list = $this->requestJson('GET', $root . '?' . $filter);
            $existing = $list['value'][0] ?? null;
        } catch (Throwable) {
            $existing = null;
        }

        if (!is_array($existing)) {
            $this->requestJson('POST', $root, [
                'code' => $packageCode,
                'packageName' => $packageCode,
            ]);
            $steps[] = 'configurationPackage aangemaakt';
        } else {
            $steps[] = 'configurationPackage bestond al';
        }

        // Upload content — endpoint kan per BC-versie verschillen
        $uploadUrl = $root . "('" . rawurlencode($packageCode) . "')/Microsoft.NAV.upload";
        $altUpload = $root . "('" . rawurlencode($packageCode) . "')/file('" . rawurlencode($packageCode) . "')/content";

        $uploaded = false;
        $uploadErrors = [];
        foreach ([$uploadUrl, $altUpload] as $u) {
            try {
                $this->requestBinary('PATCH', $u, $xlsxPath, 'application/octet-stream');
                $uploaded = true;
                $steps[] = 'package geüpload via ' . $u;
                break;
            } catch (Throwable $e) {
                $uploadErrors[] = $e->getMessage();
            }
        }
        if (!$uploaded) {
            throw new RuntimeException(
                "Package-upload mislukt. BC-fouten:\n- " . implode("\n- ", $uploadErrors)
            );
        }

        foreach (['Microsoft.NAV.import', 'Microsoft.NAV.apply'] as $action) {
            $actionUrl = $root . "('" . rawurlencode($packageCode) . "')/" . $action;
            try {
                $this->requestJson('POST', $actionUrl, new stdClass());
                $steps[] = $action . ' OK';
            } catch (Throwable $e) {
                throw new RuntimeException($action . ' mislukt: ' . $e->getMessage());
            }
        }

        return [
            'package_code' => $packageCode,
            'company_id' => $companyId,
            'steps' => $steps,
            'raw' => [],
        ];
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
