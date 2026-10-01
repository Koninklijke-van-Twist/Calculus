<?php

/**
 * Minimale Asclepius-client: ticket ophalen, xlsx-bijlagen selecteren, reactie plaatsen.
 */
final class AsclepiusClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public static function fromGlobals(): ?self
    {
        global $asclepiusApiKey, $asclepiusBase;
        $key = trim((string) ($asclepiusApiKey ?? ''));
        if ($key === '') {
            return null;
        }
        $base = trim((string) ($asclepiusBase ?? 'https://sleutels.kvt.nl/asclepius'));

        return new self($base, $key);
    }

    /** @return array<string, mixed> */
    public function getTicket(int $ticketId): array
    {
        $url = $this->baseUrl . '/api.php?id=' . $ticketId;
        $json = $this->request('GET', $url);
        if (!($json['success'] ?? false) || !isset($json['ticket']) || !is_array($json['ticket'])) {
            throw new RuntimeException('Ticket niet gevonden of API-fout: ' . ($json['error'] ?? 'onbekend'));
        }

        return $json['ticket'];
    }

    /**
     * @return list<array{id:int, original_name:string, message_id:int, mime_type:?string, file_size:int}>
     */
    public function listXlsxAttachments(int $ticketId): array
    {
        $ticket = $this->getTicket($ticketId);
        $out = [];
        $messages = is_array($ticket['messages'] ?? null) ? $ticket['messages'] : [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $attachments = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
            foreach ($attachments as $att) {
                if (!is_array($att)) {
                    continue;
                }
                $name = (string) ($att['original_name'] ?? '');
                if (!preg_match('/\.xlsx$/i', $name)) {
                    continue;
                }
                $out[] = [
                    'id' => (int) ($att['id'] ?? 0),
                    'original_name' => $name,
                    'message_id' => (int) ($message['id'] ?? 0),
                    'mime_type' => isset($att['mime_type']) ? (string) $att['mime_type'] : null,
                    'file_size' => (int) ($att['file_size'] ?? 0),
                ];
            }
        }

        return $out;
    }

    /**
     * Download bijlage via Asclepius index.php?download=ID (zelfde host, API-key header).
     */
    public function downloadAttachment(int $attachmentId, string $targetPath): void
    {
        $url = $this->baseUrl . '/index.php?download=' . $attachmentId;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init mislukt');
        }
        $fp = fopen($targetPath, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Kan doelbestand niet schrijven.');
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . $this->apiKey,
                'Accept: */*',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($ok === false || $code < 200 || $code >= 300) {
            @unlink($targetPath);
            throw new RuntimeException('Bijlage download mislukt (HTTP ' . $code . '): ' . $err);
        }
    }

    public function addTicketMessage(int $ticketId, string $message, string $senderEmail, string $senderName = 'Calculus', string $senderTitle = 'ICT'): void
    {
        $payload = [
            'action' => 'add_ticket_message',
            'ticket_id' => $ticketId,
            'message' => $message,
            'sender_email' => $senderEmail,
            'sender_name' => $senderName,
            'sender_title' => $senderTitle,
        ];
        $json = $this->request('POST', $this->baseUrl . '/api.php', $payload);
        if (!($json['success'] ?? false)) {
            throw new RuntimeException('Ticketreactie mislukt: ' . ($json['error'] ?? json_encode($json)));
        }
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, ?array $jsonBody = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init mislukt');
        }
        $headers = [
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey,
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ];
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Asclepius cURL-fout: ' . $err);
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Asclepius gaf geen JSON (HTTP ' . $code . ').');
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('Asclepius HTTP ' . $code . ': ' . ($decoded['error'] ?? $raw));
        }

        return $decoded;
    }
}
