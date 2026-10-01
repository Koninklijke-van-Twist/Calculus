<?php

/**
 * Strikte parser voor BASCALC-xlsx: leest alleen tabblad "Invoer BC".
 * Gebruikt gecachete celwaarden (zoals Excel ze opslaat). Zonder cache → fout.
 */
final class BascalcParser
{
    public const REQUIRED_SHEET = 'Invoer BC';

    /** @var list<string> */
    public const REQUIRED_HEADERS = [
        'Projectnr.',
        'Project subordernr.',
        'Basislijnversienr.',
        'Projecttaaknr.',
        'Configuratienr.',
        'Configuratieversienr.',
        'Configuratieregelnr.',
        'Regelnr.',
        'Regelsoort',
        'Soort',
        'Nr.',
        'Omschrijving',
        'Vestiging',
        'Werksoort',
        'Aantal',
        'Kostprijs (LV)',
        'Directe Kostprijs (LV)',
        'Opslaglocatie',
        'Basislijnversie omschrijving',
        'Basislijnversie in filter',
    ];

    /**
     * @return array{
     *   project_hint: string,
     *   baseline_version: string,
     *   excel_total: float,
     *   hoofdblad_total: ?float,
     *   lines: list<array<string, mixed>>,
     *   warnings: list<string>
     * }
     */
    public static function parse(string $xlsxPath): array
    {
        if (!is_file($xlsxPath)) {
            throw new InvalidArgumentException('Bestand niet gevonden.');
        }

        $zip = new ZipArchive();
        if ($zip->open($xlsxPath) !== true) {
            throw new InvalidArgumentException('Geen geldig xlsx-bestand.');
        }

        try {
            $sheetPath = self::findSheetPath($zip, self::REQUIRED_SHEET);
            if ($sheetPath === null) {
                throw new InvalidArgumentException(
                    'Tabblad "' . self::REQUIRED_SHEET . '" ontbreekt. Dit lijkt geen standaard BASCALC-bestand.'
                );
            }

            $shared = self::readSharedStrings($zip);
            $cells = self::readSheetCells($zip, $sheetPath, $shared);
        } finally {
            $zip->close();
        }

        $headerRow = self::detectHeaderRow($cells);
        if ($headerRow === null) {
            throw new InvalidArgumentException(
                'Koprij met verplichte kolommen niet gevonden op "' . self::REQUIRED_SHEET . '".'
            );
        }

        $headers = [];
        for ($c = 1; $c <= 21; $c++) {
            $headers[$c] = self::normHeader((string) ($cells[$headerRow][$c] ?? ''));
        }

        foreach (self::REQUIRED_HEADERS as $required) {
            $found = false;
            foreach ($headers as $h) {
                if (self::headersMatch($h, $required)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new InvalidArgumentException(
                    'Verplichte kolom ontbreekt op "' . self::REQUIRED_SHEET . '": ' . $required
                );
            }
        }

        $col = static function (string $name) use ($headers): int {
            foreach ($headers as $c => $h) {
                if (self::headersMatch($h, $name)) {
                    return (int) $c;
                }
            }
            throw new InvalidArgumentException('Kolom niet gevonden: ' . $name);
        };

        // Project hint: cel A4 in BASCALC-template (rij 4), of eerste dataregel
        $projectHint = trim((string) ($cells[4][1] ?? ''));
        $baselineVersion = trim((string) ($cells[4][2] ?? '1'));
        if ($baselineVersion === '') {
            $baselineVersion = '1';
        }

        $excelTotalCell = $cells[4][4] ?? null;
        $hoofdbladTotal = self::toFloatOrNull($cells[4][5] ?? null);

        $lines = [];
        $warnings = [];
        $maxRow = $cells === [] ? 0 : max(array_keys($cells));

        for ($r = $headerRow + 1; $r <= $maxRow; $r++) {
            $task = trim((string) ($cells[$r][$col('Projecttaaknr.')] ?? ''));
            $lineNo = trim((string) ($cells[$r][$col('Regelnr.')] ?? ''));
            $type = trim((string) ($cells[$r][$col('Soort')] ?? ''));
            if ($task === '' && $lineNo === '' && $type === '') {
                continue;
            }
            if ($task === '' || $lineNo === '' || $type === '') {
                $warnings[] = "Rij {$r}: onvolledige regel overgeslagen.";
                continue;
            }

            $qtyRaw = $cells[$r][$col('Aantal')] ?? null;
            $costRaw = $cells[$r][$col('Kostprijs (LV)')] ?? null;
            $directRaw = $cells[$r][$col('Directe Kostprijs (LV)')] ?? null;

            if ($qtyRaw === null || $costRaw === null) {
                throw new InvalidArgumentException(
                    "Rij {$r}: Aantal/Kostprijs ontbreekt (geen gecachete Excel-waarde). "
                    . 'Open het bestand in Excel, sla op, en upload opnieuw.'
                );
            }

            $qty = self::toFloat($qtyRaw, "Rij {$r}: Aantal");
            $unitCost = self::toFloat($costRaw, "Rij {$r}: Kostprijs");
            $direct = $directRaw === null || $directRaw === ''
                ? $unitCost
                : self::toFloat($directRaw, "Rij {$r}: Directe kostprijs");

            $lineType = trim((string) ($cells[$r][$col('Regelsoort')] ?? ''));
            if ($lineType !== '' && strcasecmp($lineType, 'Budget') !== 0) {
                $warnings[] = "Rij {$r}: Regelsoort is '{$lineType}', verwacht 'Budget'.";
            }

            $lineTotal = round($qty * $unitCost, 2);
            $lines[] = [
                'job_no' => $projectHint,
                'job_change_order_no' => (string) ($cells[$r][$col('Project subordernr.')] ?? ''),
                'baseline_version_no' => (string) ($cells[$r][$col('Basislijnversienr.')] ?? $baselineVersion),
                'job_task_no' => $task,
                'configuration_no' => (string) ($cells[$r][$col('Configuratienr.')] ?? ''),
                'configuration_version_no' => (string) ($cells[$r][$col('Configuratieversienr.')] ?? ''),
                'configuration_line_no' => (string) ($cells[$r][$col('Configuratieregelnr.')] ?? '0'),
                'line_no' => $lineNo,
                'line_type' => $lineType !== '' ? $lineType : 'Budget',
                'type' => $type,
                'no' => trim((string) ($cells[$r][$col('Nr.')] ?? '')),
                'description' => trim((string) ($cells[$r][$col('Omschrijving')] ?? '')),
                'location_code' => trim((string) ($cells[$r][$col('Vestiging')] ?? '')),
                'work_type_code' => trim((string) ($cells[$r][$col('Werksoort')] ?? '')),
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'direct_unit_cost' => $direct,
                'bin_code' => trim((string) ($cells[$r][$col('Opslaglocatie')] ?? '')),
                'baseline_version_description' => trim((string) ($cells[$r][$col('Basislijnversie omschrijving')] ?? '')),
                'baseline_version_in_filter' => self::toBoolString($cells[$r][$col('Basislijnversie in filter')] ?? 'true'),
                'baseline_version_description_2' => '',
                'line_total' => $lineTotal,
                'source_row' => $r,
            ];
        }

        if ($lines === []) {
            throw new InvalidArgumentException('Geen geldige dataregels gevonden op "Invoer BC".');
        }

        $sum = 0.0;
        foreach ($lines as $line) {
            $sum += (float) $line['line_total'];
        }
        $sum = round($sum, 2);

        $excelTotal = self::toFloatOrNull($excelTotalCell);
        if ($excelTotal === null) {
            $excelTotal = $sum;
            $warnings[] = 'Cel D4 (totaal BC IMPORT) ontbrak; som van regels gebruikt.';
        } elseif (abs($excelTotal - $sum) > 0.02) {
            $warnings[] = sprintf(
                'Totaal D4 (%.2f) wijkt af van som regels (%.2f).',
                $excelTotal,
                $sum
            );
        }

        return [
            'project_hint' => $projectHint,
            'baseline_version' => $baselineVersion,
            'excel_total' => $excelTotal,
            'hoofdblad_total' => $hoofdbladTotal,
            'lines' => $lines,
            'warnings' => $warnings,
        ];
    }

    private static function headersMatch(string $actual, string $required): bool
    {
        $a = self::normHeader($actual);
        $b = self::normHeader($required);
        if ($a === $b) {
            return true;
        }
        // RapidStart-export schrijft "Kostprijs" i.p.v. "Kostprijs (LV)"
        if ($b === self::normHeader('Kostprijs (LV)') && $a === self::normHeader('Kostprijs')) {
            return true;
        }
        if ($b === self::normHeader('Directe Kostprijs (LV)') && (
            $a === self::normHeader('Directe kostprijs (LV)')
            || $a === self::normHeader('Directe Kostprijs (LV)')
        )) {
            return true;
        }

        return false;
    }

    private static function normHeader(string $value): string
    {
        $value = str_replace("\xc2\xa0", ' ', $value);
        $value = trim($value);

        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * @param array<int, array<int, mixed>> $cells
     */
    private static function detectHeaderRow(array $cells): ?int
    {
        foreach ($cells as $r => $row) {
            $joined = [];
            foreach ($row as $v) {
                if ($v !== null && $v !== '') {
                    $joined[] = self::normHeader((string) $v);
                }
            }
            if (
                in_array(self::normHeader('Projectnr.'), $joined, true)
                && in_array(self::normHeader('Projecttaaknr.'), $joined, true)
                && in_array(self::normHeader('Aantal'), $joined, true)
            ) {
                return (int) $r;
            }
        }

        return null;
    }

    private static function findSheetPath(ZipArchive $zip, string $sheetName): ?string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            return null;
        }

        $rid = null;
        if (preg_match_all('/<sheet[^>]*name="([^"]*)"[^>]*r:id="([^"]+)"/i', $workbook, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                if ($match[1] === $sheetName) {
                    $rid = $match[2];
                    break;
                }
            }
        }
        // Some workbooks put r:id before name
        if ($rid === null && preg_match_all('/<sheet[^>]*r:id="([^"]+)"[^>]*name="([^"]*)"/i', $workbook, $m2, PREG_SET_ORDER)) {
            foreach ($m2 as $match) {
                if ($match[2] === $sheetName) {
                    $rid = $match[1];
                    break;
                }
            }
        }
        if ($rid === null) {
            return null;
        }

        if (preg_match('/Id="' . preg_quote($rid, '/') . '"[^>]*Target="([^"]+)"/', $rels, $tm)
            || preg_match('/Target="([^"]+)"[^>]*Id="' . preg_quote($rid, '/') . '"/', $rels, $tm)
        ) {
            $target = str_replace('\\', '/', $tm[1]);
            if (!str_starts_with($target, 'xl/')) {
                $target = 'xl/' . ltrim($target, '/');
            }

            return $target;
        }

        return null;
    }

    /** @return list<string> */
    private static function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false || $xml === '') {
            return [];
        }

        $out = [];
        if (preg_match_all('/<(?:\w+:)?si\b.*?<\/(?:\w+:)?si>/s', $xml, $matches)) {
            foreach ($matches[0] as $si) {
                $parts = [];
                if (preg_match_all('/<(?:\w+:)?t\b[^>]*>(.*?)<\/(?:\w+:)?t>/s', $si, $tm)) {
                    foreach ($tm[1] as $t) {
                        $parts[] = html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                }
                $out[] = implode('', $parts);
            }
        }

        return $out;
    }

    /**
     * @param list<string> $shared
     * @return array<int, array<int, mixed>>
     */
    private static function readSheetCells(ZipArchive $zip, string $sheetPath, array $shared): array
    {
        $xml = $zip->getFromName($sheetPath);
        if ($xml === false) {
            throw new InvalidArgumentException('Worksheet XML ontbreekt.');
        }

        $cells = [];
        if (!preg_match_all('/<(?:\w+:)?c\b([^>]*)>(.*?)<\/(?:\w+:)?c>/s', $xml, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $attrs = $match[1];
            $inner = $match[2];
            if (!preg_match('/\br="([A-Z]+)(\d+)"/', $attrs, $rm)) {
                continue;
            }
            $col = self::colLettersToIndex($rm[1]);
            $row = (int) $rm[2];
            $type = '';
            if (preg_match('/\bt="([^"]*)"/', $attrs, $tm)) {
                $type = $tm[1];
            }

            $value = null;
            if ($type === 'inlineStr') {
                if (preg_match('/<(?:\w+:)?t\b[^>]*>(.*?)<\/(?:\w+:)?t>/s', $inner, $tm)) {
                    $value = html_entity_decode($tm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                } else {
                    $value = '';
                }
            } elseif ($type === 's') {
                if (preg_match('/<(?:\w+:)?v\b[^>]*>(.*?)<\/(?:\w+:)?v>/s', $inner, $vm)) {
                    $idx = (int) $vm[1];
                    $value = $shared[$idx] ?? '';
                }
            } else {
                // number or cached formula result
                if (preg_match('/<(?:\w+:)?v\b[^>]*>(.*?)<\/(?:\w+:)?v>/s', $inner, $vm)) {
                    $raw = html_entity_decode($vm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                    if ($type === 'b') {
                        $value = $raw === '1' || $raw === 'true';
                    } elseif (is_numeric($raw)) {
                        $value = $raw + 0;
                    } else {
                        $value = $raw;
                    }
                } elseif (preg_match('/<(?:\w+:)?f\b/', $inner)) {
                    // formula without cached value
                    $value = null;
                }
            }

            $cells[$row][$col] = $value;
        }

        return $cells;
    }

    private static function colLettersToIndex(string $letters): int
    {
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }

        return $n;
    }

    private static function toFloat(mixed $value, string $label): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            $v = str_replace(["\xc2\xa0", ' '], '', trim($value));
            $v = str_replace(',', '.', $v);
            if (is_numeric($v)) {
                return (float) $v;
            }
        }
        throw new InvalidArgumentException("{$label}: geen getal (" . var_export($value, true) . ').');
    }

    private static function toFloatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return self::toFloat($value, 'getal');
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function toBoolString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        $s = strtolower(trim((string) $value));
        if (in_array($s, ['1', 'true', 'yes', 'ja'], true)) {
            return 'true';
        }
        if (in_array($s, ['0', 'false', 'no', 'nee'], true)) {
            return 'false';
        }

        return 'true';
    }
}
