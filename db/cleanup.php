<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tech1k <hello@tech1k.com>
/**
 * Faucet DB retention sweep. Run from cron.
 *
 * Deletes payout rows older than the retention window from every payout table,
 * then VACUUMs to reclaim space. Rate limiting only needs rows inside the claim
 * window (1h for XMR); we keep a few days as an abuse-forensics buffer. Pruning
 * also bounds table growth and limits how long claimer IPs (PII) are retained.
 *
 * Usage:
 *   php /path/to/db/cleanup.php
 *
 * Env:
 *   FAUCET_DB       path to the SQLite file (same var the faucet uses)
 *   RETENTION_DAYS  days of history to keep (default 7)
 *
 * Example crontab (daily at 04:17, log output):
 *   17 4 * * * FAUCET_DB=/var/lib/cypherfaucet/faucet.db \
 *     php /var/www/cypherfaucet/db/cleanup.php >> /var/log/cypherfaucet-cleanup.log 2>&1
 */

// CLI-only, never executable over HTTP (the db/.htaccess also blocks it).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

$dbFile = getenv('FAUCET_DB') ?: __DIR__ . '/faucet.db';
$retentionDays = (int) (getenv('RETENTION_DAYS') ?: 7);
if ($retentionDays < 1) {
    $retentionDays = 7;
}

$tables = ['xmr_stagenet_payouts', 'xmr_testnet_payouts'];

try {
    $db = new PDO("sqlite:$dbFile");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 10000');
} catch (PDOException $e) {
    fwrite(STDERR, "[cleanup] DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Which of the expected tables actually exist in this DB.
$existing = $db->query("SELECT name FROM sqlite_master WHERE type='table'")
               ->fetchAll(PDO::FETCH_COLUMN);

$stamp = gmdate('Y-m-d H:i:s');
$totalDeleted = 0;

foreach ($tables as $table) {
    if (!in_array($table, $existing, true)) {
        continue;
    }
    try {
        $stmt = $db->prepare(
            "DELETE FROM {$table} WHERE timestamp < DATETIME('now', :age)"
        );
        $stmt->execute([':age' => "-{$retentionDays} day"]);
        $n = $stmt->rowCount();
        $totalDeleted += $n;
        echo "[cleanup] {$stamp}  {$table}: deleted {$n} row(s) older than {$retentionDays}d\n";
    } catch (PDOException $e) {
        fwrite(STDERR, "[cleanup] {$table}: " . $e->getMessage() . "\n");
    }
}

// Reclaim space only if we actually removed something.
if ($totalDeleted > 0) {
    try {
        $db->exec('VACUUM');
        echo "[cleanup] {$stamp}  vacuumed ({$totalDeleted} row(s) removed total)\n";
    } catch (PDOException $e) {
        fwrite(STDERR, "[cleanup] VACUUM failed: " . $e->getMessage() . "\n");
    }
} else {
    echo "[cleanup] {$stamp}  nothing to delete\n";
}
