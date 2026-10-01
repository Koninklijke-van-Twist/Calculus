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

        $entries = [];
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

            // Lees alle entries, vervang sheet/table, herschrijf zip met alleen Deflate/Store.
            // (BC/.NET weigert sommige ZipArchive-rewrite compressiemethodes.)
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    continue;
                }
                $data = $zip->getFromIndex($i);
                if ($data === false) {
                    throw new RuntimeException('Kon zip-entry niet lezen: ' . $name);
                }
                $entries[$name] = $data;
            }
            $entries['xl/worksheets/sheet1.xml'] = $newSheet;
            $entries['xl/tables/table1.xml'] = $newTable;
        } finally {
            $zip->close();
        }

        self::rewriteZipForBc($targetPath, $entries);
    }

    /**
     * Herschrijf als zip die BC/.NET accepteert.
     * PHP ZipArchive::CM_DEFLATE zet flag 0x0002; CM_STORE weigert BC soms ook.
     * Handmatige writer: method 8 (Deflate), flag 0 —zelfde als Excel/BC-export.
     *
     * @param array<string, string> $entries
     */
    private static function rewriteZipForBc(string $path, array $entries): void
    {
        $tmp = $path . '.tmp';
        @unlink($tmp);
        $localParts = [];
        $centralParts = [];
        $offset = 0;
        $now = getdate();
        // DOS time/date
        $dosTime = ((int) $now['hours'] << 11) | ((int) $now['minutes'] << 5) | (((int) $now['seconds']) >> 1);
        $dosDate = ((((int) $now['year'] - 1980) << 9) | ((int) $now['mon'] << 5) | (int) $now['mday']);

        foreach ($entries as $name => $data) {
            if (!is_string($data)) {
                throw new RuntimeException('Ongeldige zip-entry: ' . $name);
            }
            $nameBytes = $name;
            // ZIP paths use forward slashes; UTF-8 names OK with flag bit 11 but we keep ASCII paths
            $compressed = gzdeflate($data, 6);
            if ($compressed === false) {
                throw new RuntimeException('gzdeflate mislukt voor ' . $name);
            }
            $crc = crc32($data);
            if ($crc < 0) {
                // crc32 can be signed on 32-bit; force unsigned 32-bit
                $crc = $crc & 0xFFFFFFFF;
            }
            $csize = strlen($compressed);
            $usize = strlen($data);
            $nlen = strlen($nameBytes);

            $local = pack(
                'VvvvvvVVVvv',
                0x04034b50, // local sig
                20,         // version needed
                0,          // flag (geen data-descriptor, geen "max deflate")
                8,          // method Deflate
                $dosTime,
                $dosDate,
                $crc,
                $csize,
                $usize,
                $nlen,
                0           // extra len
            ) . $nameBytes . $compressed;

            $centralParts[] = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50, // central sig
                20,         // version made by
                20,         // version needed
                0,          // flag
                8,          // method
                $dosTime,
                $dosDate,
                $crc,
                $csize,
                $usize,
                $nlen,
                0,          // extra
                0,          // comment
                0,          // disk start
                0,          // int attr
                0,          // ext attr
                $offset
            ) . $nameBytes;

            $localParts[] = $local;
            $offset += strlen($local);
        }

        $body = implode('', $localParts);
        $central = implode('', $centralParts);
        $count = count($entries);
        $end = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $count,
            $count,
            strlen($central),
            strlen($body),
            0
        );

        if (file_put_contents($tmp, $body . $central . $end) === false) {
            throw new RuntimeException('Kon tijdelijke RapidStart-zip niet schrijven.');
        }
        if (!@rename($tmp, $path)) {
            @unlink($path);
            if (!@rename($tmp, $path)) {
                throw new RuntimeException('Kon herschreven RapidStart-pakket niet opslaan.');
            }
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
