<?php

declare(strict_types=1);

/**
 * Mímir-client voor OData-reads.
 *
 * Als $mimirApi in auth.php staat, gaan OData-reads eerst naar Mímir.
 * Faalt die aanroep (cURL/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload),
 * dan valt Calculus terug op de eigen directe BC-OData van de gekozen environment.
 * Na de eerste fout in dit PHP-proces wordt Mímir overgeslagen.
 * Zonder $mimirApi blijft alleen die directe route actief.
 * Zonder bruikbare BC-credentials wordt de oorspronkelijke Mímir-fout opnieuw gegooid.
 *
 * Een HTTP 404 (onbekende entity) is wél een Mímir-fout, maar opent het circuit
 * pas als elke kandidaat-entity 404 gaf. Zo kan de tweede gepubliceerde naam
 * nog via Mímir. Transportfouten en andere HTTP-statussen openen het circuit meteen.
 */
final class MimirClient
{
    /** @var callable(string, string, ?string, list<string>): array{status:int, body:string}|null */
    public static $transport = null;

    /** @var array{open: bool, error: ?Throwable} */
    private static array $circuit = [
        'open' => false,
        'error' => null,
    ];

    /** @var list<string> */
    private const FAILURE_PREFIXES = [
        'Mímir cURL error:',
        'Mímir HTTP ',
        'Mímir gaf ongeldige JSON',
        'Mímir error:',
        'Mímir query-antwoord mist',
    ];

    public static function apiKey(): string
    {
        global $mimirApi;
        if (!isset($mimirApi) || !is_string($mimirApi)) {
            return '';
        }

        return trim($mimirApi);
    }

    public static function enabled(): bool
    {
        return self::apiKey() !== '';
    }

    public static function baseUrl(): string
    {
        global $mimirBase;
        if (isset($mimirBase) && is_string($mimirBase) && trim($mimirBase) !== '') {
            return rtrim(trim($mimirBase), '/');
        }

        return 'https://sleutels.kvt.nl/mimir/api';
    }

    public static function circuitOpen(): bool
    {
        return self::$circuit['open'] === true;
    }

    public static function lastError(): ?Throwable
    {
        $error = self::$circuit['error'];

        return $error instanceof Throwable ? $error : null;
    }

    public static function trip(Throwable $exception): void
    {
        if (self::$circuit['open'] === true) {
            return;
        }
        self::$circuit['open'] = true;
        self::$circuit['error'] = $exception;
    }

    public static function resetCircuit(): void
    {
        self::$circuit['open'] = false;
        self::$circuit['error'] = null;
    }

    public static function connectTimeoutSeconds(): int
    {
        return 10;
    }

    public static function timeoutSecondsForSapi(string $sapi): int
    {
        return strtolower($sapi) === 'cli' ? 600 : 90;
    }

    public static function timeoutSeconds(): int
    {
        return self::timeoutSecondsForSapi(PHP_SAPI);
    }

    public static function isFailure(Throwable $exception): bool
    {
        $message = $exception->getMessage();
        foreach (self::FAILURE_PREFIXES as $prefix) {
            if (strpos($message, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Onbekende tabel/entity. Geen reden om Mímir voor een andere kandidaat over te slaan.
     */
    public static function isEntityMiss(Throwable $exception): bool
    {
        return strpos($exception->getMessage(), 'Mímir HTTP 404') === 0;
    }

    /**
     * @param list<string> $secrets
     */
    public static function logFallback(Throwable $exception, array $secrets): void
    {
        error_log('[Calculus] Mímir failed, falling back to direct OData: ' . self::redact($exception->getMessage(), $secrets));
    }

    /**
     * @param list<string> $secrets
     */
    public static function logEnvironmentMismatch(string $reported, string $selected, string $company, array $secrets): void
    {
        $message = 'Mímir antwoordde voor environment ' . $reported
            . ' (bedrijf ' . $company . '); gekozen environment is ' . $selected
            . '; directe OData.';
        error_log('[Calculus] ' . self::redact($message, $secrets));
    }

    /**
     * @param list<string> $secrets
     */
    public static function redact(string $message, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }
            $message = str_replace($secret, '[redacted]', $message);
        }
        $sanitized = preg_replace('/(Bearer\s+)\S+/i', '$1[redacted]', $message);

        return is_string($sanitized) ? $sanitized : $message;
    }

    /**
     * Eén tabelquery. Gooit een Mímir-fout (zie isFailure) zonder het circuit te openen;
     * de aanroeper opent het circuit pas als deze read niet meer via Mímir kan.
     *
     * @param array{filter?: string, top?: int, max_age?: int, select?: list<string>} $options
     * @return array<string, mixed>
     */
    public static function query(string $company, string $table, array $options = []): array
    {
        if (self::circuitOpen()) {
            $previous = self::lastError();
            if ($previous instanceof Throwable) {
                throw $previous;
            }
            throw new RuntimeException('Mímir overgeslagen na eerdere fout in dit verzoek.');
        }

        $body = [
            'company' => $company,
            'table' => $table,
            'max_age' => max(0, (int) ($options['max_age'] ?? 600)),
            'top' => array_key_exists('top', $options) ? (int) $options['top'] : 0,
        ];
        if (isset($options['select']) && is_array($options['select']) && $options['select'] !== []) {
            $body['select'] = array_values($options['select']);
        }
        $filter = trim((string) ($options['filter'] ?? ''));
        if ($filter !== '') {
            $body['filter'] = $filter;
        }

        $decoded = self::request('POST', 'query.php', $body);
        if (!isset($decoded['value']) || !is_array($decoded['value'])) {
            throw new RuntimeException("Mímir query-antwoord mist 'value'.");
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @return array<string, mixed>
     */
    public static function request(string $method, string $path, ?array $jsonBody = null): array
    {
        $apiKey = self::apiKey();
        if ($apiKey === '') {
            throw new RuntimeException('Mímir API-sleutel ontbreekt ($mimirApi).');
        }

        if (self::circuitOpen()) {
            $previous = self::lastError();
            if ($previous instanceof Throwable) {
                throw $previous;
            }
            throw new RuntimeException('Mímir overgeslagen na eerdere fout in dit verzoek.');
        }

        $url = self::baseUrl() . '/' . ltrim($path, '/');
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
            'X-API-Key: ' . $apiKey,
        ];
        $payload = null;
        if ($jsonBody !== null) {
            $encoded = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new RuntimeException('Mímir request JSON encode mislukt.');
            }
            $payload = $encoded;
            $headers[] = 'Content-Type: application/json';
        }

        if (is_callable(self::$transport)) {
            $result = (self::$transport)(strtoupper($method), $url, $payload, $headers);
            $code = (int) ($result['status'] ?? 0);
            $raw = (string) ($result['body'] ?? '');
        } else {
            [$code, $raw] = self::curl($method, $url, $headers, $payload);
        }

        $decoded = json_decode($raw, true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($decoded) ? (string) ($decoded['error'] ?? $raw) : $raw;
            throw new RuntimeException('Mímir HTTP ' . $code . ': ' . $message);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Mímir gaf ongeldige JSON terug.');
        }
        $errorField = $decoded['error'] ?? null;
        if ($errorField !== null && $errorField !== '' && $errorField !== false) {
            $message = is_string($errorField) ? $errorField : (string) json_encode($errorField, JSON_UNESCAPED_UNICODE);
            throw new RuntimeException('Mímir error: ' . $message);
        }

        return $decoded;
    }

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: string}
     */
    private static function curl(string $method, string $url, array $headers, ?string $payload): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Mímir cURL error: curl_init mislukt');
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => self::connectTimeoutSeconds(),
            CURLOPT_TIMEOUT => self::timeoutSeconds(),
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'Calculus-MimirClient/1.0',
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Mímir cURL error: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$code, (string) $raw];
    }
}
