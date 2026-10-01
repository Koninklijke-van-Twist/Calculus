<?php

/**
 * Bouwt een RapidStart-xlsx voor pakket NEWBUILD_CALCULATIE.
 * Behoudt xmlMaps/connections/tables uit het BC-export-template.
 */
final class RapidStartBuilder
{
    public const PACKAGE_CODE = 'NEWBUILD_CALCULATIE';
    public const TABLE_CAPTION = 'Projectbasislijnregel';
    public const TABLE_ID = '11332917';

    /**
     * @param list<array<string, mixed>> $lines  output van BascalcParser (na project-override)
     */
    public static function build(string $templatePath, string $targetPath, string $jobNo, array $lines): void
    {
        if (!is_file($templatePath)) {
            throw new RuntimeException('RapidStart-template ontbreekt: ' . $templatePath);
        }

        if (!@copy($templatePath, $targetPath)) {
            throw new RuntimeException('Kon template niet kopiëren naar ' . $targetPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($targetPath) !== true) {
            throw new RuntimeException('Kon doel-xlsx niet openen.');
        }

        try {
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $tableXml = $zip->getFromName('xl/tables/table1.xml');
            if ($sheetXml === false || $tableXml === false) {
                throw new RuntimeException('Template mist sheet1.xml of table1.xml.');
            }

            $lastDataRow = 3 + count($lines); // header op 3, data vanaf 4
            $newSheet = self::rebuildSheet($sheetXml, $jobNo, $lines);
            $newTable = preg_replace(
                '/ref="A3:U\d+"/',
                'ref="A3:U' . $lastDataRow . '"',
                $tableXml
            );
            if (!is_string($newTable)) {
                throw new RuntimeException('table1.xml kon niet worden bijgewerkt.');
            }

            $zip->addFromString('xl/worksheets/sheet1.xml', $newSheet);
            $zip->addFromString('xl/tables/table1.xml', $newTable);
        } finally {
            $zip->close();
        }
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private static function rebuildSheet(string $original, string $jobNo, array $lines): string
    {
        $prefix = 'x:';
        if (!str_contains($original, '<x:worksheet') && str_contains($original, '<worksheet')) {
            $prefix = '';
        }

        // Keep workbook header up to sheetData open, replace sheetData content
        if (!preg_match('/^(.*<(?:\w+:)?sheetData>)(.*)(<\/(?:\w+:)?sheetData>.*)$/s', $original, $m)) {
            throw new RuntimeException('sheetData niet gevonden in template.');
        }

        $p = $prefix;
        $rows = [];
        $rows[] = self::rowXml($p, 1, [
            'A' => self::PACKAGE_CODE,
            'B' => self::TABLE_CAPTION,
            'C' => self::TABLE_ID,
        ]);

        $headers = [
            'A' => 'Projectnr.',
            'B' => 'Project subordernr.',
            'C' => 'Basislijnversienr.',
            'D' => 'Projecttaaknr.',
            'E' => 'Configuratienr.',
            'F' => 'Configuratieversienr.',
            'G' => 'Configuratieregelnr.',
            'H' => 'Regelnr.',
            'I' => 'Regelsoort',
            'J' => 'Soort',
            'K' => 'Nr.',
            'L' => 'Omschrijving',
            'M' => 'Vestiging',
            'N' => 'Werksoort',
            'O' => 'Aantal',
            'P' => 'Kostprijs',
            'Q' => 'Directe kostprijs (LV)',
            'R' => 'Opslaglocatie',
            'S' => 'Basislijnversie omschrijving',
            'T' => 'Basislijnversie in filter',
            'U' => 'Basislijnversie omschrijving 2',
        ];
        $rows[] = self::rowXml($p, 3, $headers);

        $r = 4;
        foreach ($lines as $line) {
            $bin = trim((string) ($line['bin_code'] ?? ''));
            if ($bin === '') {
                $bin = $jobNo;
            }
            $vals = [
                'A' => $jobNo,
                'B' => (string) ($line['job_change_order_no'] ?? ''),
                'C' => (string) ($line['baseline_version_no'] ?? '1'),
                'D' => (string) ($line['job_task_no'] ?? ''),
                'E' => (string) ($line['configuration_no'] ?? ''),
                'F' => (string) ($line['configuration_version_no'] ?? ''),
                'G' => (string) ($line['configuration_line_no'] ?? '0'),
                'H' => (string) ($line['line_no'] ?? ''),
                'I' => (string) ($line['line_type'] ?? 'Budget'),
                'J' => (string) ($line['type'] ?? ''),
                'K' => (string) ($line['no'] ?? ''),
                'L' => (string) ($line['description'] ?? ''),
                'M' => (string) ($line['location_code'] ?? ''),
                'N' => (string) ($line['work_type_code'] ?? ''),
                'O' => self::numStr($line['quantity'] ?? 0),
                'P' => self::numStr($line['unit_cost'] ?? 0),
                'Q' => self::numStr($line['direct_unit_cost'] ?? ($line['unit_cost'] ?? 0)),
                'R' => $bin,
                'S' => (string) ($line['baseline_version_description'] ?? 'Baseline version 1 created by KVT\\LOGICVISION'),
                'T' => (string) ($line['baseline_version_in_filter'] ?? 'true'),
                'U' => (string) ($line['baseline_version_description_2'] ?? ''),
            ];
            $rows[] = self::rowXml($p, $r, $vals);
            $r++;
        }

        return $m[1] . implode('', $rows) . $m[3];
    }

    /** @param array<string, string> $cells */
    private static function rowXml(string $prefix, int $row, array $cells): string
    {
        $p = $prefix;
        $xml = '<' . $p . 'row r="' . $row . '">';
        foreach ($cells as $col => $value) {
            $ref = $col . $row;
            $xml .= '<' . $p . 'c r="' . $ref . '" s="1" t="inlineStr"><' . $p . 'is><' . $p . 't xml:space="preserve">'
                . self::xmlEscape((string) $value)
                . '</' . $p . 't></' . $p . 'is></' . $p . 'c>';
        }
        $xml .= '</' . $p . 'row>';

        return $xml;
    }

    private static function numStr(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        $f = (float) $value;
        if (abs($f - round($f)) < 1e-9) {
            return (string) (int) round($f);
        }

        return rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');
    }

    private static function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
