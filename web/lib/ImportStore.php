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
