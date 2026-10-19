<?php

declare(strict_types=1);

/**
 * SQLite-allowlist voor Calculus-gebruikersbeheer.
 * Run: php tests/user_access_test.php
 */

require dirname(__DIR__) . '/web/lib/ImportStore.php';
require dirname(__DIR__) . '/web/lib/ActionAccess.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_same($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fail($label . ' verwacht ' . var_export($expected, true) . ' kreeg ' . var_export($actual, true));
    }
}

$dbPath = sys_get_temp_dir() . '/calculus-access-' . bin2hex(random_bytes(4)) . '.sqlite';
@unlink($dbPath);
$store = new ImportStore($dbPath);

assert_same([], $store->listAllowedEmails(), 'lege store');
assert_same(2, $store->seedAllowedUsers([
    'Analyst@kvt.nl',
    'planner@kvt.nl',
    'niet-een-email',
    'analyst@kvt.nl',
], 'auth.php'), 'seed unieke geldige e-mails');
assert_same(['analyst@kvt.nl', 'planner@kvt.nl'], $store->listAllowedEmails(), 'seed-lijst');
assert_same(false, $store->addAllowedUser('analyst@kvt.nl', 'opesket@kvt.nl'), 'dubbele add');
assert_same(true, $store->addAllowedUser('nieuw@kvt.nl', 'Opesket@kvt.nl'), 'nieuwe add');
assert_same(true, $store->removeAllowedUser('planner@kvt.nl'), 'remove');
assert_same(false, $store->removeAllowedUser('planner@kvt.nl'), 'remove opnieuw');
assert_same(['analyst@kvt.nl', 'nieuw@kvt.nl'], $store->listAllowedEmails(), 'na add/remove');

$rows = $store->listAllowedUsers();
assert_same(2, count($rows), 'rij-count');
assert_same('nieuw@kvt.nl', $rows[1]['email'], 'rij e-mail');
assert_same('opesket@kvt.nl', $rows[1]['added_by'], 'added_by lowercase');

try {
    $store->addAllowedUser('geen-email', 'opesket@kvt.nl');
    fail('ongeldig e-mailadres moest falen');
} catch (InvalidArgumentException) {
    // verwacht
}

assert_same(
    true,
    calculus_email_may_access('nieuw@kvt.nl', $store->listAllowedEmails(), ['alleen-auth@kvt.nl']),
    'store-gebruiker'
);
assert_same(
    false,
    calculus_email_may_access('alleen-auth@kvt.nl', $store->listAllowedEmails(), ['alleen-auth@kvt.nl']),
    'auth valt weg als store gevuld is'
);
assert_same(
    true,
    calculus_email_may_access('tfalken@kvt.nl', $store->listAllowedEmails(), []),
    'vaste admin ondanks store'
);

@unlink($dbPath);

echo "OK user access\n";
