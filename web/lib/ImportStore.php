<?php

/**
 * Audit-log + hash-waarschuwing (bestandshash + project). Blokkeert imports niet.
 */
final class ImportStore
{
    private PDO $pdo;

    public function __construct(string $sqlitePath)
    {
        $dir = dirname($sqlitePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Data-map kon niet worden aangemaakt.');
        }

        $this->pdo = new PDO('sqlite:' . $sqlitePath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA busy_timeout = 3000');
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS imports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL,
                user_email TEXT NOT NULL,
                ticket_id INTEGER,
                project_no TEXT NOT NULL,
                environment TEXT NOT NULL,
                company TEXT,
                source_filename TEXT NOT NULL,
                file_sha256 TEXT NOT NULL,
                line_count INTEGER NOT NULL,
                excel_total REAL NOT NULL,
                bc_total REAL,
                status TEXT NOT NULL,
                package_path TEXT,
                bc_response TEXT,
                notes TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_imports_hash_project
             ON imports (file_sha256, project_no)'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_prefs (
                user_email TEXT PRIMARY KEY,
                last_company TEXT,
                updated_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS allowed_users (
                email TEXT PRIMARY KEY,
                added_at TEXT NOT NULL,
                added_by TEXT NOT NULL
            )'
        );
    }

    /**
     * @return list<string>
     */
    public function listAllowedEmails(): array
    {
        $stmt = $this->pdo->query(
            'SELECT email FROM allowed_users ORDER BY email COLLATE NOCASE'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $emails = [];
        foreach ($rows as $row) {
            if (!is_string($row)) {
                continue;
            }
            $email = strtolower(trim($row));
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    /**
     * @return list<array{email:string,added_at:string,added_by:string}>
     */
    public function listAllowedUsers(): array
    {
        $stmt = $this->pdo->query(
            'SELECT email, added_at, added_by
             FROM allowed_users
             ORDER BY email COLLATE NOCASE'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '') {
                continue;
            }
            $out[] = [
                'email' => $email,
                'added_at' => (string) ($row['added_at'] ?? ''),
                'added_by' => strtolower(trim((string) ($row['added_by'] ?? ''))),
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $emails
     * @return int aantal nieuw toegevoegde rijen
     */
    public function seedAllowedUsers(array $emails, string $addedBy): int
    {
        $added = 0;
        foreach ($emails as $email) {
            $normalized = strtolower(trim((string) $email));
            if ($normalized === '' || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if ($this->addAllowedUser($normalized, $addedBy)) {
                $added++;
            }
        }

        return $added;
    }

    public function addAllowedUser(string $email, string $addedBy): bool
    {
        $email = strtolower(trim($email));
        $addedBy = strtolower(trim($addedBy));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Ongeldig e-mailadres.');
        }
        if ($addedBy === '') {
            $addedBy = 'unknown';
        }
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO allowed_users (email, added_at, added_by)
             VALUES (:e, :a, :b)'
        );
        $stmt->execute([
            ':e' => $email,
            ':a' => gmdate('c'),
            ':b' => $addedBy,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function removeAllowedUser(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }
        $stmt = $this->pdo->prepare('DELETE FROM allowed_users WHERE email = :e');
        $stmt->execute([':e' => $email]);

        return $stmt->rowCount() > 0;
    }

    public function getLastCompany(string $userEmail): string
    {
        $email = strtolower(trim($userEmail));
        if ($email === '') {
            return '';
        }
        $stmt = $this->pdo->prepare(
            'SELECT last_company FROM user_prefs WHERE user_email = :e LIMIT 1'
        );
        $stmt->execute([':e' => $email]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? trim($value) : '';
    }

    public function setLastCompany(string $userEmail, string $company): void
    {
        $email = strtolower(trim($userEmail));
        $company = trim($company);
        if ($email === '' || $company === '') {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_prefs (user_email, last_company, updated_at)
             VALUES (:e, :c, :u)
             ON CONFLICT(user_email) DO UPDATE SET
                last_company = excluded.last_company,
                updated_at = excluded.updated_at'
        );
        $stmt->execute([
            ':e' => $email,
            ':c' => $company,
            ':u' => gmdate('c'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findPrevious(string $sha256, string $projectNo): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, created_at, user_email, status, excel_total, line_count, source_filename
             FROM imports
             WHERE file_sha256 = :h AND project_no = :p
             ORDER BY id DESC
             LIMIT 10'
        );
        $stmt->execute([':h' => $sha256, ':p' => $projectNo]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function record(array $row): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO imports (
                created_at, user_email, ticket_id, project_no, environment, company,
                source_filename, file_sha256, line_count, excel_total, bc_total,
                status, package_path, bc_response, notes
             ) VALUES (
                :created_at, :user_email, :ticket_id, :project_no, :environment, :company,
                :source_filename, :file_sha256, :line_count, :excel_total, :bc_total,
                :status, :package_path, :bc_response, :notes
             )'
        );
        $stmt->execute([
            ':created_at' => $row['created_at'] ?? gmdate('c'),
            ':user_email' => (string) ($row['user_email'] ?? ''),
            ':ticket_id' => $row['ticket_id'] ?? null,
            ':project_no' => (string) ($row['project_no'] ?? ''),
            ':environment' => (string) ($row['environment'] ?? ''),
            ':company' => $row['company'] ?? null,
            ':source_filename' => (string) ($row['source_filename'] ?? ''),
            ':file_sha256' => (string) ($row['file_sha256'] ?? ''),
            ':line_count' => (int) ($row['line_count'] ?? 0),
            ':excel_total' => (float) ($row['excel_total'] ?? 0),
            ':bc_total' => $row['bc_total'] ?? null,
            ':status' => (string) ($row['status'] ?? 'unknown'),
            ':package_path' => $row['package_path'] ?? null,
            ':bc_response' => $row['bc_response'] ?? null,
            ':notes' => $row['notes'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
