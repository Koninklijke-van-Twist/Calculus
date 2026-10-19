<?php

declare(strict_types=1);
require_once __DIR__ . '/kvt_protect_dirs.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/auth.php';
require __DIR__ . '/logincheck.php';
require_once __DIR__ . '/lib/BascalcParser.php';
require_once __DIR__ . '/lib/RapidStartBuilder.php';
require_once __DIR__ . '/lib/ImportStore.php';
require_once __DIR__ . '/lib/BcAutomation.php';
require_once __DIR__ . '/lib/CompanyCatalog.php';

const CALCULUS_TEMPLATE = __DIR__ . '/templates/NEWBUILD_CALCULATIE_template.xlsx';

function calculus_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function calculus_existing_via(?string $source): string
{
    return match ($source) {
        'mimir' => 'via Mímir',
        'odata-fallback' => 'via directe OData, nadat Mímir een fout gaf',
        'odata-environment' => 'via directe OData; Mímir hoort bij een ander environment',
        default => 'via OData',
    };
}

function calculus_user_email(): string
{
    $user = calculus_session_bag()['user'] ?? null;
    $email = '';
    if (is_array($user)) {
        $email = strtolower(trim((string) ($user['email'] ?? '')));
    }

    return $email !== '' ? $email : 'unknown@local';
}

function calculus_user_is_admin(): bool
{
    return calculus_email_is_admin(calculus_user_email());
}

/**
 * Eerste openen van het admin-panel: kopieer auth.php-$allowedUsers naar SQLite
 * zodat de UI meteen de huidige lijst toont. Daarna is SQLite leidend.
 *
 * @param mixed $authAllowedUsers
 */
function calculus_seed_access_from_auth(ImportStore $store, mixed $authAllowedUsers): void
{
    if ($store->listAllowedEmails() !== []) {
        return;
    }
    $seed = calculus_normalize_allowed_emails($authAllowedUsers);
    if ($seed === []) {
        return;
    }
    $store->seedAllowedUsers($seed, 'auth.php');
}

function calculus_require_admin(): void
{
    if (!calculus_user_is_admin()) {
        throw new RuntimeException('Alleen Calculus-admins mogen gebruikersbeheer openen.');
    }
}

function calculus_remember_company(string $company): void
{
    $company = trim($company);
    if ($company === '') {
        return;
    }
    try {
        calculus_store()->setLastCompany(calculus_user_email(), $company);
    } catch (Throwable) {
        // voorkeur is niet kritiek voor de importflow
    }
}

/**
 * @param list<array{name:string,environment:string}> $companies
 */
function calculus_selected_company(array $companies, string $posted): string
{
    $saved = '';
    try {
        $saved = calculus_store()->getLastCompany(calculus_user_email());
    } catch (Throwable) {
        $saved = '';
    }

    $candidates = [
        $posted,
        $saved,
        trim((string) ($GLOBALS['calculusDefaultCompany'] ?? '')),
    ];
    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }
        foreach ($companies as $row) {
            if (strcasecmp($row['name'], $candidate) === 0) {
                calculus_remember_company($row['name']);

                return $row['name'];
            }
        }
    }

    foreach ($companies as $row) {
        if (stripos($row['environment'], 'fat') !== false) {
            calculus_remember_company($row['name']);

            return $row['name'];
        }
    }

    $fallback = $companies[0]['name'] ?? '';
    if ($fallback !== '') {
        calculus_remember_company($fallback);
    }

    return $fallback;
}

function calculus_environment_note(string $environment): string
{
    if ($environment === '') {
        return 'De omgeving volgt uit het gekozen bedrijf.';
    }
    if (stripos($environment, 'fat') === false) {
        return 'Omgeving voor dit bedrijf: ' . $environment . ' (live — extra bevestiging bij apply).';
    }

    return 'Omgeving voor dit bedrijf: ' . $environment . ' (FAT).';
}

function calculus_store(): ImportStore
{
    return new ImportStore(__DIR__ . '/data/calculus.sqlite');
}

function calculus_ensure_dirs(): void
{
    foreach (['uploads', 'data/packages', 'cache'] as $rel) {
        $path = __DIR__ . '/' . $rel;
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }
}

/**
 * @return array{path:string, name:string, sha256:string}
 */
function calculus_receive_upload(): array
{
    calculus_ensure_dirs();
    if (!isset($_FILES['bascalc']) || !is_array($_FILES['bascalc'])) {
        throw new InvalidArgumentException('Geen bestand geüpload.');
    }
    $err = (int) ($_FILES['bascalc']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Upload mislukt (code ' . $err . ').');
    }
    $name = (string) ($_FILES['bascalc']['name'] ?? 'upload.xlsx');
    if (!preg_match('/\.xlsx$/i', $name)) {
        throw new InvalidArgumentException('Alleen .xlsx toegestaan.');
    }
    $tmp = (string) ($_FILES['bascalc']['tmp_name'] ?? '');
    $dest = __DIR__ . '/uploads/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.xlsx';
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Kon upload niet opslaan.');
    }

    return [
        'path' => $dest,
        'name' => $name,
        'sha256' => hash_file('sha256', $dest) ?: '',
    ];
}

/** @param array<string, mixed> $parsed */
function calculus_apply_project(array $parsed, string $projectNo): array
{
    foreach ($parsed['lines'] as &$line) {
        $line['job_no'] = $projectNo;
        if (trim((string) ($line['bin_code'] ?? '')) === '' || preg_match('/^PRJ/i', (string) $line['bin_code'])) {
            $line['bin_code'] = $projectNo;
        }
    }
    unset($line);
    $parsed['project_hint'] = $projectNo;

    return $parsed;
}

/**
 * @return array<string, mixed>
 */
function calculus_require_preview_session(string $token): array
{
    $session = calculus_session_bag()['calculus_preview'] ?? null;
    if ($token === '' || !is_array($session) || !hash_equals((string) ($session['token'] ?? ''), $token)) {
        throw new InvalidArgumentException('Preview verlopen of ongeldig. Maak opnieuw een voorbeeld.');
    }
    if ((time() - (int) ($session['created'] ?? 0)) > 3600) {
        calculus_persist_session(static function (): void {
            unset($_SESSION['calculus_preview']);
        });
        throw new InvalidArgumentException('Preview ouder dan 1 uur. Maak opnieuw een voorbeeld.');
    }

    return $session;
}

/**
 * @param array<string, mixed> $session
 * @return array{path:string, basename:string}
 */
function calculus_build_package_from_session(array $session): array
{
    $parsed = $session['parsed'];
    $projectNo = (string) $session['project_no'];
    calculus_ensure_dirs();
    $packagePath = __DIR__ . '/data/packages/' . $projectNo . '_' . date('Ymd_His') . '.xlsx';
    RapidStartBuilder::build(CALCULUS_TEMPLATE, $packagePath, $projectNo, $parsed['lines']);

    return [
        'path' => $packagePath,
        'basename' => basename($packagePath),
    ];
}

$flashError = '';
$flashOk = '';
$preview = null;
$showAdmin = false;
$adminUsers = [];

$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? ''));

try {
    if ($action === 'admin' || $action === 'admin_add_user' || $action === 'admin_remove_user') {
        calculus_require_admin();
        $showAdmin = true;
        $store = calculus_store();
        calculus_seed_access_from_auth($store, $allowedUsers ?? null);

        if ($action === 'admin_add_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $newEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
            if ($store->addAllowedUser($newEmail, calculus_user_email())) {
                $flashOk = $newEmail . ' heeft nu toegang tot Calculus.';
            } else {
                $flashOk = $newEmail . ' stond al op de lijst.';
            }
        }

        if ($action === 'admin_remove_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $removeEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
            if (calculus_email_is_admin($removeEmail)) {
                throw new InvalidArgumentException('Vaste admins kunnen niet uit de allowlist worden gehaald.');
            }
            if ($store->removeAllowedUser($removeEmail)) {
                $flashOk = $removeEmail . ' heeft geen toegang meer.';
            } else {
                $flashOk = $removeEmail . ' stond niet op de lijst.';
            }
        }

        $adminUsers = $store->listAllowedUsers();
    }

    if ($action === 'preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $projectNo = strtoupper(trim((string) ($_POST['project_no'] ?? '')));
        $postedCompany = trim((string) ($_POST['company'] ?? ''));
        $existingMode = trim((string) ($_POST['existing_mode'] ?? 'abort'));

        if ($projectNo === '' || !preg_match('/^PRJ\d+/i', $projectNo)) {
            throw new InvalidArgumentException('Projectnummer verplicht (vorm PRJ…).');
        }
        $resolvedCompany = CompanyCatalog::resolve($postedCompany);
        $company = $resolvedCompany['name'];
        $environment = $resolvedCompany['environment'];
        calculus_remember_company($company);

        $file = calculus_receive_upload();
        $parsed = BascalcParser::parse($file['path']);
        $parsed = calculus_apply_project($parsed, $projectNo);

        $store = calculus_store();
        $previous = $store->findPrevious($file['sha256'], $projectNo);

        $existingLines = null;
        $existingError = null;
        $existingTotal = null;
        $existingSource = null;
        $bc = null;
        try {
            $bc = BcAutomation::fromGlobals($environment, $company);
            $existingLines = $bc->fetchExistingBaselineLines($projectNo);
            $existingSource = $bc->baselineReadSource();
            $existingTotal = 0.0;
            foreach ($existingLines as $row) {
                $q = (float) ($row['Quantity'] ?? $row['Aantal'] ?? 0);
                $c = (float) ($row['Unit_Cost'] ?? $row['UnitCost'] ?? $row['Kostprijs'] ?? 0);
                $existingTotal += $q * $c;
            }
            $existingTotal = round($existingTotal, 2);
        } catch (Throwable $e) {
            $existingError = $e->getMessage();
            if ($bc instanceof BcAutomation) {
                $existingSource = $bc->baselineReadSource();
            }
        }

        $token = bin2hex(random_bytes(16));
        $previewPayload = [
            'token' => $token,
            'created' => time(),
            'file' => $file,
            'parsed' => $parsed,
            'project_no' => $projectNo,
            'environment' => $environment,
            'company' => $company,
            'existing_mode' => $existingMode,
            'existing_count' => is_array($existingLines) ? count($existingLines) : null,
            'existing_total' => $existingTotal,
            'existing_error' => $existingError,
            'existing_source' => $existingSource,
        ];
        calculus_persist_session(static function () use ($previewPayload): void {
            $_SESSION['calculus_preview'] = $previewPayload;
        });

        $preview = $previewPayload;
        $preview['previous'] = $previous;
        $preview['existing_lines'] = $existingLines;
    }

    if ($action === 'download_excel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = trim((string) ($_POST['token'] ?? ''));
        $session = calculus_require_preview_session($token);
        $pkg = calculus_build_package_from_session($session);
        $file = $session['file'];
        $parsed = $session['parsed'];
        $projectNo = (string) $session['project_no'];
        $environment = (string) $session['environment'];
        $company = (string) $session['company'];

        $store = calculus_store();
        $store->record([
            'user_email' => calculus_user_email(),
            'ticket_id' => null,
            'project_no' => $projectNo,
            'environment' => $environment,
            'company' => $company,
            'source_filename' => (string) ($file['name'] ?? ''),
            'file_sha256' => (string) ($file['sha256'] ?? ''),
            'line_count' => count($parsed['lines']),
            'excel_total' => (float) $parsed['excel_total'],
            'bc_total' => null,
            'status' => 'package_ready',
            'package_path' => $pkg['path'],
            'bc_response' => null,
            'notes' => 'RapidStart-pakket gedownload; nog niet toegepast in BC.',
        ]);

        // Houd preview vast zodat "Toepassen in BC" daarna nog kan.
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $pkg['basename'] . '"');
        header('Content-Length: ' . (string) filesize($pkg['path']));
        readfile($pkg['path']);
        exit;
    }

    if ($action === 'confirm_apply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = trim((string) ($_POST['token'] ?? ''));
        $session = calculus_require_preview_session($token);

        $existingCount = (int) ($session['existing_count'] ?? 0);
        $existingMode = (string) ($session['existing_mode'] ?? 'abort');
        if ($existingCount > 0 && $existingMode === 'abort') {
            throw new RuntimeException(
                'Project heeft al ' . $existingCount . ' basislijnregel(s). Kies in de preview "Toch toevoegen" of breek af.'
            );
        }

        $file = $session['file'];
        $parsed = $session['parsed'];
        $projectNo = (string) $session['project_no'];
        $environment = (string) $session['environment'];
        $company = (string) $session['company'];

        if (stripos($environment, 'fat') === false && !isset($_POST['confirm_live'])) {
            throw new InvalidArgumentException('Live-environment vereist expliciete bevestiging (checkbox).');
        }

        // RapidStart-xlsx blijft beschikbaar als download/backup; schrijven gaat via OData.
        $pkg = calculus_build_package_from_session($session);
        $packagePath = $pkg['path'];

        $bcResponse = null;
        $status = 'package_ready';
        $notes = 'RapidStart-pakket gegenereerd; nog niet toegepast in BC.';

        try {
            $bc = BcAutomation::fromGlobals($environment, $company);
            $result = $bc->postBaselineLines($projectNo, $parsed['lines']);
            $bcResponse = json_encode($result, JSON_UNESCAPED_UNICODE);
            $status = 'applied';
            $notes = 'OData JobBaselineLines: ' . implode('; ', $result['steps']);
        } catch (Throwable $e) {
            $bcResponse = $e->getMessage();
            $status = 'apply_failed';
            $notes = 'OData-apply mislukt; RapidStart-pakket staat klaar op schijf.';
            $flashError = 'BC-fout (letterlijk):\n' . $e->getMessage();
        }

        $store = calculus_store();
        $importId = $store->record([
            'user_email' => calculus_user_email(),
            'ticket_id' => null,
            'project_no' => $projectNo,
            'environment' => $environment,
            'company' => $company,
            'source_filename' => (string) ($file['name'] ?? ''),
            'file_sha256' => (string) ($file['sha256'] ?? ''),
            'line_count' => count($parsed['lines']),
            'excel_total' => (float) $parsed['excel_total'],
            'bc_total' => null,
            'status' => $status,
            'package_path' => $packagePath,
            'bc_response' => $bcResponse,
            'notes' => $notes,
        ]);

        if ($status === 'applied') {
            $flashOk = 'Import #' . $importId . ' toegepast in BC. Pakket: ' . basename($packagePath);
        } elseif ($status === 'apply_failed') {
            $flashOk = 'Import #' . $importId . ': pakket klaar, apply mislukt. Download hieronder.';
        }

        $preview = [
            'result' => true,
            'import_id' => $importId,
            'status' => $status,
            'package_path' => $packagePath,
            'package_basename' => basename($packagePath),
            'parsed' => $parsed,
            'project_no' => $projectNo,
            'environment' => $environment,
            'bc_response' => $bcResponse,
        ];
        calculus_persist_session(static function (): void {
            unset($_SESSION['calculus_preview']);
        });
    }

    if ($action === 'download_package' && isset($_GET['file'])) {
        // /data/packages/*.xlsx is met .htaccess dicht. Dit is het enige
        // downloadpad en het draait ná logincheck.php.
        $base = basename((string) $_GET['file']);
        $realPath = calculus_safe_package_path(__DIR__ . '/data/packages', $base);
        if ($realPath === null) {
            throw new InvalidArgumentException('Package niet gevonden.');
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $base . '"');
        header('Content-Length: ' . (string) filesize($realPath));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($realPath);
        exit;
    }
} catch (Throwable $e) {
    $flashError = $e->getMessage();
}

$companyCatalog = ['companies' => [], 'warnings' => []];
try {
    $companyCatalog = CompanyCatalog::load();
} catch (Throwable $catalogError) {
    $flashError = trim($flashError . "\n" . $catalogError->getMessage());
}
$companyRows = $companyCatalog['companies'];
$companyWarnings = $companyCatalog['warnings'];
$selectedProject = strtoupper(trim((string) ($_POST['project_no'] ?? '')));
$selectedCompany = calculus_selected_company($companyRows, trim((string) ($_POST['company'] ?? '')));
$selectedEnvironment = '';
foreach ($companyRows as $companyRow) {
    if ($companyRow['name'] === $selectedCompany) {
        $selectedEnvironment = $companyRow['environment'];
        break;
    }
}
$companyEnvMap = [];
foreach ($companyRows as $companyRow) {
    $companyEnvMap[$companyRow['name']] = $companyRow['environment'];
}

?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Calculus — BASCALC → BC</title>
  <link rel="stylesheet" href="calculus.css">
  <link rel="icon" href="favicon.ico">
</head>
<body>
<div class="wrap">
  <header class="app-header">
    <div>
      <div class="tag">sleutels.kvt.nl / calculus</div>
      <h1>Calculus</h1>
    </div>
    <div class="header-meta">
      <div class="muted"><?= calculus_h(calculus_user_email()) ?></div>
      <?php if (calculus_user_is_admin()): ?>
        <?php if ($showAdmin): ?>
          <a class="btn secondary" href="?">Terug naar import</a>
        <?php else: ?>
          <a class="btn secondary" href="?action=admin">Gebruikersbeheer</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($flashError !== ''): ?>
    <div class="banner err"><?= nl2br(calculus_h($flashError)) ?></div>
  <?php endif; ?>
  <?php if ($flashOk !== ''): ?>
    <div class="banner ok"><?= calculus_h($flashOk) ?></div>
  <?php endif; ?>

  <?php if ($showAdmin): ?>
  <div class="card">
    <h2>Gebruikersbeheer</h2>
    <p class="muted">
      Alleen vaste admins (<?= calculus_h(implode(', ', calculus_admin_emails())) ?>)
      kunnen deze lijst wijzigen. Zodra hier gebruikers staan, geldt alleen deze
      lijst plus de vaste admins — niet meer de open fallback uit <code>auth.php</code>.
    </p>

    <h3 class="subhead">Vaste admins</h3>
    <ul class="user-list">
      <?php foreach (calculus_admin_emails() as $adminEmail): ?>
        <li>
          <code><?= calculus_h($adminEmail) ?></code>
          <span class="muted">altijd toegang · niet verwijderbaar</span>
        </li>
      <?php endforeach; ?>
    </ul>

    <h3 class="subhead">Overige toegang</h3>
    <?php if ($adminUsers === []): ?>
      <p class="muted">Nog geen extra gebruikers. Voeg hieronder een e-mailadres toe.</p>
    <?php else: ?>
      <div class="table-wrap admin-table">
        <table>
          <thead>
            <tr>
              <th>E-mail</th>
              <th>Toegevoegd</th>
              <th>Door</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($adminUsers as $row): ?>
              <tr>
                <td><code><?= calculus_h($row['email']) ?></code></td>
                <td class="muted"><?= calculus_h($row['added_at']) ?></td>
                <td class="muted"><?= calculus_h($row['added_by']) ?></td>
                <td>
                  <?php if (calculus_email_is_admin($row['email'])): ?>
                    <span class="muted">admin</span>
                  <?php else: ?>
                    <form method="post" class="inline-form" onsubmit="return confirm('Toegang intrekken voor <?= calculus_h($row['email']) ?>?');">
                      <input type="hidden" name="email" value="<?= calculus_h($row['email']) ?>">
                      <button type="submit" name="action" value="admin_remove_user" class="danger">Intrekken</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <form method="post" class="admin-add">
      <div class="field">
        <label for="admin_email">E-mailadres toevoegen</label>
        <input type="email" name="email" id="admin_email" required placeholder="collega@kvt.nl" autocomplete="off">
      </div>
      <div class="actions">
        <button type="submit" name="action" value="admin_add_user">Toegang verlenen</button>
      </div>
    </form>
  </div>
  <?php else: ?>

  <?php foreach ($companyWarnings as $companyWarning): ?>
    <div class="banner warn"><?= calculus_h($companyWarning) ?></div>
  <?php endforeach; ?>

  <div class="card">
    <h2>1. Bron &amp; project</h2>
    <form method="post" enctype="multipart/form-data">
      <div class="grid">
        <div class="field">
          <label for="project_no">Projectnummer</label>
          <input type="text" name="project_no" id="project_no" required placeholder="PRJ2608376" value="<?= calculus_h($selectedProject) ?>">
        </div>
        <div class="field">
          <label for="company">BC-bedrijf</label>
          <?php if ($companyRows === []): ?>
            <select name="company" id="company" disabled>
              <option value="">Geen BC-bedrijven</option>
            </select>
          <?php else: ?>
            <select name="company" id="company" required>
              <?php foreach ($companyRows as $companyRow): ?>
                <option value="<?= calculus_h($companyRow['name']) ?>" <?= $companyRow['name'] === $selectedCompany ? 'selected' : '' ?>>
                  <?= calculus_h($companyRow['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <p class="muted" id="company-env-note"><?= calculus_h(calculus_environment_note($selectedEnvironment)) ?></p>
        </div>
        <div class="field">
          <label for="bascalc">BASCALC .xlsx</label>
          <input type="file" name="bascalc" id="bascalc" required accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
        </div>
        <div class="field">
          <label for="existing_mode">Als er al regels op het project staan</label>
          <select name="existing_mode" id="existing_mode">
            <option value="abort">Afbreken (veilig)</option>
            <option value="add">Toch toevoegen (geen auto-delete)</option>
          </select>
        </div>
      </div>
      <div class="actions">
        <button type="submit" name="action" value="preview">Voorbeeld</button>
      </div>
    </form>
  </div>

  <?php if (is_array($preview) && empty($preview['result'])): ?>
    <?php
      $parsed = $preview['parsed'];
      $lines = $parsed['lines'];
      $previous = $preview['previous'] ?? [];
      $existingLines = $preview['existing_lines'] ?? null;
    ?>
    <div class="card">
      <h2>2. Voorbeeld</h2>
      <div class="stats">
        <div class="stat"><strong><?= count($lines) ?></strong><span>regels</span></div>
        <div class="stat"><strong>€ <?= calculus_h(number_format((float) $parsed['excel_total'], 2, ',', '.')) ?></strong><span>totaal Basislijn (Excel)</span></div>
        <div class="stat"><strong><?= calculus_h((string) $preview['project_no']) ?></strong><span>project</span></div>
        <div class="stat"><strong><?= calculus_h((string) ($preview['company'] ?? '')) ?></strong><span>bedrijf</span></div>
        <div class="stat"><strong><?= calculus_h((string) $preview['environment']) ?></strong><span>environment</span></div>
      </div>

      <?php if (!empty($parsed['warnings'])): ?>
        <?php foreach ($parsed['warnings'] as $w): ?>
          <div class="banner warn"><?= calculus_h((string) $w) ?></div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (!empty($previous)): ?>
        <div class="banner warn">
          Dit bestand (zelfde hash) is eerder al op dit project geladen
          (informatief — blokkeert niet; opnieuw uploaden voor lokale tests mag):
          <?php foreach ($previous as $p): ?>
            <br>#<?= (int) $p['id'] ?> — <?= calculus_h((string) $p['created_at']) ?>
            door <?= calculus_h((string) $p['user_email']) ?>
            (<?= calculus_h((string) $p['status']) ?>)
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($preview['existing_error'])): ?>
        <div class="banner warn">
          <?= calculus_h((string) $preview['existing_error']) ?>
          (<?= calculus_h(calculus_existing_via(isset($preview['existing_source']) ? (string) $preview['existing_source'] : null)) ?>).
        </div>
      <?php elseif (is_array($existingLines)): ?>
        <?php if ($existingLines === []): ?>
          <div class="banner ok">Geen bestaande basislijnregels gevonden op dit project (<?= calculus_h(calculus_existing_via(isset($preview['existing_source']) ? (string) $preview['existing_source'] : null)) ?>).</div>
        <?php else: ?>
          <div class="banner warn">
            <?= count($existingLines) ?> bestaande regel(s) op project,
            geschat totaal € <?= calculus_h(number_format((float) ($preview['existing_total'] ?? 0), 2, ',', '.')) ?>
            (<?= calculus_h(calculus_existing_via(isset($preview['existing_source']) ? (string) $preview['existing_source'] : null)) ?>).
            Calculus verwijdert of overschrijft niets automatisch.
            Gekozen modus: <strong><?= calculus_h((string) $preview['existing_mode']) ?></strong>.
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Taak</th><th>Regel</th><th>Soort</th><th>Nr.</th><th>Omschrijving</th>
              <th class="num">Aantal</th><th class="num">Kostprijs</th><th class="num">Totaal</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($lines as $line): ?>
            <tr class="<?= ((float) $line['line_total'] == 0.0) ? 'zero' : '' ?>">
              <td><?= calculus_h((string) $line['job_task_no']) ?></td>
              <td><?= calculus_h((string) $line['line_no']) ?></td>
              <td><?= calculus_h((string) $line['type']) ?></td>
              <td><?= calculus_h((string) $line['no']) ?></td>
              <td><?= calculus_h((string) $line['description']) ?></td>
              <td class="num"><?= calculus_h(number_format((float) $line['quantity'], 4, ',', '.')) ?></td>
              <td class="num"><?= calculus_h(number_format((float) $line['unit_cost'], 4, ',', '.')) ?></td>
              <td class="num"><?= calculus_h(number_format((float) $line['line_total'], 2, ',', '.')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <form method="post" style="margin-top:16px">
        <input type="hidden" name="token" value="<?= calculus_h((string) $preview['token']) ?>">
        <?php if (stripos((string) $preview['environment'], 'fat') === false): ?>
          <div class="live-warn">
            <label>
              <input type="checkbox" name="confirm_live" value="1">
              Ik bevestig dat ik op <strong>live</strong>-environment
              <code><?= calculus_h((string) $preview['environment']) ?></code> wil schrijven.
            </label>
          </div>
        <?php endif; ?>
        <div class="actions">
          <button type="submit" name="action" value="download_excel" class="secondary">Download Excel</button>
          <button type="submit" name="action" value="confirm_apply">Toepassen in BC</button>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <?php if (is_array($preview) && !empty($preview['result'])): ?>
    <div class="card">
      <h2>3. Resultaat</h2>
      <div class="stats">
        <div class="stat"><strong>#<?= (int) $preview['import_id'] ?></strong><span>import-id</span></div>
        <div class="stat"><strong><?= calculus_h((string) $preview['status']) ?></strong><span>status</span></div>
        <div class="stat"><strong><?= count($preview['parsed']['lines']) ?></strong><span>regels</span></div>
        <div class="stat"><strong>€ <?= calculus_h(number_format((float) $preview['parsed']['excel_total'], 2, ',', '.')) ?></strong><span>Excel-totaal</span></div>
      </div>
      <p>
        <a class="btn" href="?action=download_package&amp;file=<?= rawurlencode((string) $preview['package_basename']) ?>">
          Download Excel
        </a>
      </p>
      <?php if (!empty($preview['bc_response'])): ?>
        <pre style="white-space:pre-wrap;background:#f8fafc;border:1px solid var(--line);padding:12px;border-radius:8px;font-size:12px"><?= calculus_h((string) $preview['bc_response']) ?></pre>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php endif; /* !showAdmin */ ?>
</div>

<?php if (!$showAdmin && $companyEnvMap !== []): ?>
<script>
(function () {
  var map = <?= json_encode($companyEnvMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var select = document.getElementById('company');
  var note = document.getElementById('company-env-note');
  if (!select || !note) {
    return;
  }
  function noteFor(environment) {
    if (!environment) {
      return 'De omgeving volgt uit het gekozen bedrijf.';
    }
    if (environment.toLowerCase().indexOf('fat') === -1) {
      return 'Omgeving voor dit bedrijf: ' + environment + ' (live — extra bevestiging bij apply).';
    }
    return 'Omgeving voor dit bedrijf: ' + environment + ' (FAT).';
  }
  function refresh() {
    note.textContent = noteFor(map[select.value] || '');
  }
  select.addEventListener('change', refresh);
  refresh();
})();
</script>
<?php endif; ?>
</body>
</html>
