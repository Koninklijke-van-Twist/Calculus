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
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->environment = trim($environment);
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
            throw new RuntimeException('baseUrl ontbreekt in auth.php');
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
     * Probeert bestaande basislijnregels te lezen via OData (als gepubliceerd).
     * Faalt hard met duidelijke melding als het entity-set niet bestaat.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchExistingBaselineLines(string $jobNo): array
    {
        $candidates = [
            "Company('" . $this->odataEscape($this->companyName) . "')/Projectbasislijnregel",
            "Company('" . $this->odataEscape($this->companyName) . "')/LVS_JobChngeOrderBudgetLne",
        ];
        $lastError = '';
        foreach ($candidates as $entity) {
            $url = $this->odataRoot() . '/' . $entity
                . '?$filter=JobNo eq \'' . $this->odataEscape($jobNo) . '\''
                . '&$top=500';
            try {
                $json = $this->requestJson('GET', $url);
                $rows = $json['value'] ?? [];

                return is_array($rows) ? $rows : [];
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new RuntimeException(
            'Kon bestaande basislijnregels niet lezen via OData. '
            . 'Entity mogelijk niet gepubliceerd. Laatste fout: ' . $lastError
        );
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
            $list = $this->requestJson('GET', $root . '?$filter=code eq \'' . $this->odataEscape($packageCode) . '\'');
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
            throw new RuntimeException('curl_init mislukt');
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
            throw new RuntimeException('BC cURL-fout: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('BC HTTP ' . $code . ': ' . $raw);
        }
        if (trim((string) $raw) === '') {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('BC gaf geen JSON: ' . $raw);
        }

        return $decoded;
    }

    private function requestBinary(string $method, string $url, string $filePath, string $contentType): void
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init mislukt');
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
