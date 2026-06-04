<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tech1k <hello@tech1k.com>
// Free software under the GNU AGPL v3 or later. See LICENSE.
/**
 * Single entry point for the Monero Stagenet/Testnet faucets.
 *
 * Both nets are defined in the $FAUCETS map below; the active one is chosen by
 * the ?net= parameter that .htaccess sets when rewriting the pretty URLs
 * (/xmr-stagenet -> ?net=stagenet, /xmr-testnet -> ?net=testnet). Keeping both
 * configs here means the two nets can't drift apart, which is what once let
 * the testnet page check its rate limit against the stagenet table.
 */

// ---- Faucet definitions (both nets, one place) --------------------------
$FAUCETS = [
    'stagenet' => [
        'net_label'      => 'Stagenet',
        'currency'       => 'sXMR',
        'nettype'        => 'stagenet',
        'rpc_port'       => 38088,
        'table'          => 'xmr_stagenet_payouts',
        'explorer_tx'    => '', // stagenet.xmrchain.com unreliable; show txid + payment proof. Re-enable: 'https://stagenet.xmrchain.com/tx/'
        'address_hint'   => 'starts with 5 or 7',
        'canonical'      => '/xmr-stagenet',
        // Set 'explorer_tx' => '' to show the bare txid (no link) when the
        // dev-net explorers are down.
        // Optional: 'daemon_url' => 'http://127.0.0.1:38081/json_rpc', 'expected_host' => 'cypherfaucet.com',
    ],
    'testnet' => [
        'net_label'      => 'Testnet',
        'currency'       => 'tXMR',
        'nettype'        => 'testnet',
        'rpc_port'       => 28088,
        'table'          => 'xmr_testnet_payouts',
        'explorer_tx'    => '', // testnet.xmrchain.com unreliable; show txid + payment proof. Re-enable: 'https://testnet.xmrchain.com/tx/'
        'address_hint'   => 'starts with 9, A, or B',
        'canonical'      => '/xmr-testnet',
    ],
];

// Which net? Set by the .htaccess rewrite; reject anything not in the map.
$net = $_GET['net'] ?? '';
if (!isset($FAUCETS[$net])) {
    http_response_code(404);
    exit;
}
$faucet = $FAUCETS[$net];

// ---- Config -------------------------------------------------------------
$xmr_payout_amount = 0.01;

$net_label    = $faucet['net_label'];         // "Stagenet" / "Testnet"
$currency     = $faucet['currency'];          // "sXMR" / "tXMR"
$rpc_port     = $faucet['rpc_port'];          // 38088 / 28088
$table        = $faucet['table'];             // "xmr_stagenet_payouts" / ...
$nettype      = $faucet['nettype'];           // "stagenet" / "testnet"
$explorer_tx  = $faucet['explorer_tx'] ?? ''; // "https://.../tx/", or '' to show the bare txid
$address_hint = $faucet['address_hint'];      // placeholder hint
$canonical    = $faucet['canonical'];         // "/xmr-stagenet"
$expected_host = $faucet['expected_host'] ?? null; // optional captcha host pin

// Daemon JSON-RPC, used only for the sync-status line. Defaults to the
// standard monerod port convention (wallet RPC port minus 7: 38088->38081,
// 28088->28081). Set 'daemon_url' => null in the wrapper to disable the
// daemon comparison (the line then just shows the wallet's block height).
$daemon_url = array_key_exists('daemon_url', $faucet)
    ? $faucet['daemon_url']
    : "http://127.0.0.1:" . ($rpc_port - 7) . "/json_rpc";

// ---- Local config (secrets, not committed) ------------------------------
// Copy config.example.php to config.php and fill in your values. config.php
// is gitignored so secrets never reach the repo.
$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];

// Cloudflare Turnstile keys (sitekey is public; secret must stay private).
define('TURNSTILE_SECRET',  $config['turnstile_secret']  ?? '');
define('TURNSTILE_SITEKEY', $config['turnstile_sitekey'] ?? '');

// Public link to the source, shown in the footer to satisfy AGPL section 13.
$source_url = $config['source_url'] ?? 'https://github.com/Tech1k/cypherfaucet';

// Optional mainnet XMR address for the "support the faucet" FAQ entry. Empty by
// default, so the public code doesn't ask for donations; set it in config.php
// on your own deployment to enable the entry.
$mainnet_donate = $config['mainnet_donate'] ?? '';

// Per-net "return unused coins" address, from config (donate_stagenet /
// donate_testnet). Empty when unset, so the public code ships no addresses; the
// donate card and out-of-coins prompt hide gracefully until you set these.
$donate_addr = $config["donate_{$nettype}"] ?? '';
$donate_safe = htmlspecialchars($donate_addr, ENT_QUOTES, 'UTF-8'); // display; keep $donate_addr raw for comparisons

// Wallet RPC. Credentials come from config (or leave blank if the wallet runs
// with --disable-rpc-login bound to localhost).
$rpcUrl  = "http://127.0.0.1:$rpc_port/json_rpc";
$rpcUser = $config['rpc_user'] ?? '';
$rpcPass = $config['rpc_pass'] ?? '';

$display_form = "";
$active_err   = "";

// ---- Helpers ------------------------------------------------------------

/**
 * Resolve the client IP for rate limiting.
 *
 * Behind Cloudflare, CF-Connecting-IP is set by Cloudflare and cannot be
 * forged by the client (CF overwrites any incoming value). We do NOT trust
 * X-Forwarded-For / Client-IP, since those are attacker-controlled and would
 * let anyone reset their per-IP bucket just by sending a header.
 *
 * For full assurance also verify REMOTE_ADDR is within Cloudflare's published
 * IP ranges (and/or enable Authenticated Origin Pulls) so the CF header is
 * only honoured on requests that actually came through Cloudflare.
 */
function resolve_client_ip(): string
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Verify a Cloudflare Turnstile token via siteverify. Kept provider-agnostic
 * in name so the captcha can be swapped again without touching call sites.
 */
function verify_captcha(string $response, string $ip, ?string $expectedHost = null): bool
{
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'secret'   => TURNSTILE_SECRET,
        'response' => $response,
        'remoteip' => $ip,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $out = curl_exec($ch);
    $failed = curl_errno($ch);
    curl_close($ch);
    if ($failed) {
        return false;
    }
    $data = json_decode($out, true);
    if (empty($data['success'])) {
        return false;
    }
    // Optional: bind the token to our own host so a token solved on another
    // property sharing this secret can't be replayed here. Only enforced when
    // the wrapper sets 'expected_host' and Turnstile returned a hostname.
    if ($expectedHost !== null && !empty($data['hostname']) && $data['hostname'] !== $expectedHost) {
        return false;
    }
    return true;
}

/**
 * Wallet balance in XMR, cached briefly so ordinary page views (and crawlers)
 * don't each hit monero-wallet-rpc. Returns null if the wallet can't be
 * reached or gives no balance.
 */
function get_wallet_balance(string $url, string $user, string $pass, string $nettype, string $cacheDir, int $ttl = 20): ?array
{
    // Cache next to the DB (ideally outside the webroot) rather than in the
    // shared, world-readable system temp dir, so other tenants on shared
    // hosting can't read/spoof it. Returns total + unlocked (spendable) XMR.
    $cacheFile = rtrim($cacheDir, '/') . "/.balance_cache_{$nettype}.json";
    if (is_readable($cacheFile)) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (isset($cached['ts'], $cached['total'], $cached['unlocked']) && (time() - (int) $cached['ts']) < $ttl) {
            return ['total' => (float) $cached['total'], 'unlocked' => (float) $cached['unlocked']];
        }
    }
    $resp = xmr_rpc($url, 'get_balance', [], $user, $pass);
    if ($resp === null || !isset($resp['result']['balance'])) {
        return null;
    }
    $bal = [
        'total'    => $resp['result']['balance'] / 1e12,
        'unlocked' => ($resp['result']['unlocked_balance'] ?? 0) / 1e12,
    ];
    @file_put_contents($cacheFile, json_encode(['ts' => time()] + $bal), LOCK_EX);
    return $bal;
}

/**
 * Sync status for the liveness line. Always returns the wallet's scanned
 * height; adds the daemon height + a 'synced' flag when the daemon is
 * reachable. Cached briefly. Returns null only if the wallet RPC is down.
 */
function get_node_status(string $walletUrl, ?string $daemonUrl, string $user, string $pass, string $cacheDir, int $ttl = 20): ?array
{
    $cacheFile = rtrim($cacheDir, '/') . "/.height_cache_" . md5($walletUrl) . ".json";
    if (is_readable($cacheFile)) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (isset($cached['ts']) && (time() - (int) $cached['ts']) < $ttl) {
            return $cached['status'];
        }
    }

    $wallet = xmr_rpc($walletUrl, 'get_height', [], $user, $pass);
    if ($wallet === null || !isset($wallet['result']['height'])) {
        return null;
    }
    $status = ['height' => (int) $wallet['result']['height'], 'target' => null, 'synced' => null];

    if ($daemonUrl !== null) {
        // Daemon get_info is unauthenticated on the restricted RPC port.
        $info = xmr_rpc($daemonUrl, 'get_info', [], '', '');
        if ($info !== null && isset($info['result']['height'])) {
            $status['target'] = (int) $info['result']['height'];
            // Allow a 1-block lag (the wallet scans just behind the tip).
            $status['synced'] = $status['height'] >= $status['target'] - 1;
        }
    }

    @file_put_contents($cacheFile, json_encode(['ts' => time(), 'status' => $status]), LOCK_EX);
    return $status;
}

/**
 * Call the wallet JSON-RPC. Returns the decoded array, or null on transport
 * error so the caller can fall back to the generic error message.
 */
function xmr_rpc(string $url, string $method, array $params, string $user, string $pass): ?array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'jsonrpc' => '2.0',
        'id'      => '0',
        'method'  => $method,
        'params'  => $params,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $out = curl_exec($ch);
    $failed = curl_errno($ch);
    curl_close($ch);
    if ($failed) {
        return null;
    }
    return json_decode($out, true);
}

function card(string $headerClass, string $bodyClass, string $title, string $bodyHtml): string
{
    $hc = $headerClass ? " $headerClass" : "";
    return <<<HTML
            <div class="card{$bodyClass}">
                <div class="card-header{$hc}"><b>{$title}</b></div>
                <div class="card-body">{$bodyHtml}</div>
            </div>
HTML;
}

/**
 * Human "x ago" for a stored UTC timestamp (Y-m-d H:i:s). Returns '' on bad
 * input. Parsed as UTC so it's correct regardless of the server timezone.
 */
function time_ago(string $utcTimestamp): string
{
    try {
        $then = (new DateTime($utcTimestamp, new DateTimeZone('UTC')))->getTimestamp();
    } catch (Exception $e) {
        return '';
    }
    $secs = max(0, time() - $then);
    if ($secs < 60)   { return $secs <= 1 ? 'just now' : "{$secs} seconds ago"; }
    $mins = intdiv($secs, 60);
    if ($mins < 60)   { return $mins === 1 ? '1 minute ago' : "{$mins} minutes ago"; }
    $hours = intdiv($mins, 60);
    if ($hours < 24)  { return $hours === 1 ? '1 hour ago' : "{$hours} hours ago"; }
    $days = intdiv($hours, 24);
    return $days === 1 ? '1 day ago' : "{$days} days ago";
}

// ---- Database -----------------------------------------------------------
// Prefer a path OUTSIDE the document root (set FAUCET_DB in the vhost/PHP-FPM
// env) so the SQLite file holding claimer IPs can never be downloaded over
// HTTP. Falls back to the in-repo path for local/dev use.
$dbFile = getenv('FAUCET_DB') ?: __DIR__ . '/db/faucet.db';
try {
    $db = new PDO("sqlite:$dbFile");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 5000');
} catch (PDOException $e) {
    // Log the detail (incl. path) server-side; never expose it to the client.
    error_log("[xmr-faucet] DB connection failed for {$dbFile}: " . $e->getMessage());
    http_response_code(503);
    die("The faucet is temporarily unavailable. Please try again later.");
}

$error_msg = card('alert', ' alertborder', 'Faucet Error',
    "<p>There seems to be an error with the faucet. Please <a href='/contact'>contact me</a> if this error persists, sorry for the inconvenience.</p>");

$ip = resolve_client_ip();

// ---- Balance ------------------------------------------------------------
$bal = get_wallet_balance($rpcUrl, $rpcUser, $rpcPass, $nettype, dirname($dbFile));

if ($bal === null) {
    $balance = 0;
    $display_form = "display: none;";
    $active_err   = $error_msg;
} else {
    $balance  = $bal['total'];
    $unlocked = $bal['unlocked'];

    // Monero locks every output for 10 blocks (~20 min), including the change
    // that comes back from each payout. So gate on UNLOCKED balance, not total:
    // otherwise a claim can pass the balance check and then fail at transfer
    // with "not enough unlocked money" while recent change is still locked.
    // The wallet is funded with many small outputs (~0.015 each, batch-sent
    // from the donation address) so there's usually a spendable one available.
    // Hide the form only when there isn't enough unlocked for one payout + fee.
    $min_unlocked = $xmr_payout_amount + 0.01;
    if ($unlocked < $min_unlocked) {
        $display_form = "display: none;";
        if ($balance < $min_unlocked) {
            $out_msg = "The faucet is running low on coins. Please check back later!";
            if ($donate_addr !== '') {
                $out_msg = "The faucet is running low on coins. Please consider returning some {$currency} to <strong>{$donate_safe}</strong> to keep the faucet running!";
            }
            $active_err = card('', '', 'Error - Out of Coins', "<p>{$out_msg}</p>");
        } else {
            $active_err = card('', '', 'Coins Locking',
                "<p>The faucet's coins are briefly locked after recent payouts (Monero locks outputs for about 20 minutes). Please try again in a few minutes.</p>");
        }
    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        // ---- Captcha ----------------------------------------------------
        $captcha = $_POST['cf-turnstile-response'] ?? '';
        if (empty(trim($captcha)) || !verify_captcha($captcha, $ip, $expected_host)) {
            $active_err = card('alert', ' alertborder', 'Captcha Error',
                "<p>You must complete the captcha, this is so that we can reduce bot and spam attacks on our faucet.</p>");
        } else {
            $xmr_address = trim($_POST['address'] ?? '');

            if ($xmr_address === '') {
                $active_err = card('', '', 'Error - No Address',
                    "<p>Please enter your Monero {$net_label} address and try again.</p>");
            } elseif (strlen($xmr_address) > 120) {
                // Monero addresses are ~95 (standard/subaddress) to 106
                // (integrated) chars. Reject anything longer up front rather
                // than shipping a huge blob to the RPC.
                $active_err = card('alert', ' alertborder', 'Error - Invalid Address',
                    "<p>Please make sure this is a valid Monero {$net_label} address and that the capitalization and typing are correct, then try again!</p>");
            } elseif ($xmr_address === $donate_addr) {
                // No point paying out to the faucet's own donation address: it
                // just loops the coins back and wastes the claim slot and a fee.
                $active_err = card('alert', ' alertborder', 'Error - Invalid Address',
                    "<p>That's the faucet's donation address. Please enter your own Monero {$net_label} address.</p>");
            } else {
                // ---- Validate address (must match THIS net) -------------
                $validation = xmr_rpc($rpcUrl, 'validate_address', [
                    'address'        => $xmr_address,
                    'any_net_type'   => true,
                    'allow_openalias' => false,
                ], $rpcUser, $rpcPass);

                if ($validation === null) {
                    // Wallet unreachable, not the user's fault, so show the
                    // generic faucet error rather than "invalid address".
                    $active_err = $error_msg;
                } elseif (!(isset($validation['result']['valid']) && $validation['result']['valid'] === true)
                          || ($validation['result']['nettype'] ?? '') !== $nettype) {
                    $active_err = card('alert', ' alertborder', 'Error - Invalid Address',
                        "<p>Please make sure this is a valid Monero {$net_label} address and that the capitalization and typing are correct, then try again!</p>");
                } else {
                    // ---- Atomically check rate limit + reserve a slot ---
                    // BEGIN IMMEDIATE takes the write lock up front so two
                    // concurrent claims can't both pass the check and both
                    // trigger a transfer. We insert a reservation row (NULL
                    // txid) before the network transfer, then fill in the
                    // txid on success or delete it on failure.
                    $alreadyClaimed = false;
                    $lastclaim      = null;
                    $reservationId  = null;

                    try {
                        $db->exec('BEGIN IMMEDIATE');

                        $check = $db->prepare(
                            "SELECT timestamp FROM {$table}
                             WHERE (payout_address = :addr OR ip_address = :ip)
                               AND timestamp >= DATETIME('now', '-1 hour')
                             ORDER BY timestamp DESC LIMIT 1"
                        );
                        $check->execute([':addr' => $xmr_address, ':ip' => $ip]);
                        $recent = $check->fetch(PDO::FETCH_ASSOC);

                        if ($recent) {
                            $alreadyClaimed = true;
                            $lastclaim = $recent['timestamp'];
                        } else {
                            $reserve = $db->prepare(
                                "INSERT INTO {$table} (ip_address, payout_amount, payout_address, transaction_id, timestamp)
                                 VALUES (:ip, :amount, :addr, NULL, :ts)"
                            );
                            // Store UTC so it lines up with SQLite's DATETIME('now'),
                            // which is also UTC. Using local time here would skew the
                            // 1-hour window by the server's timezone offset.
                            $reserve->execute([
                                ':ip'     => $ip,
                                ':amount' => $xmr_payout_amount,
                                ':addr'   => $xmr_address,
                                ':ts'     => gmdate('Y-m-d H:i:s'),
                            ]);
                            $reservationId = (int) $db->lastInsertId();
                        }

                        $db->exec('COMMIT');
                    } catch (PDOException $e) {
                        try { $db->exec('ROLLBACK'); } catch (PDOException $ignore) {}
                        error_log("[xmr-faucet] reservation failed: " . $e->getMessage());
                        $active_err = $error_msg;
                    }

                    if ($alreadyClaimed) {
                        $display_form = "display: none;";
                        $next = new DateTime($lastclaim, new DateTimeZone('UTC'));
                        $next->modify('+1 hour');
                        $formatted = $next->format('n/j/Y \a\t g:i:s A') . ' UTC';
                        $active_err = card('alert', ' alertborder', 'Error - Already Claimed',
                            "<p>It seems that you have already claimed from the faucet once within the last hour. Please try again later or <a href=\"/contact\" id=\"site_link\"><b>contact us</b></a> if you think this is an error.<br/><br/>You may claim again on <span class=\"utc_next\">{$formatted}</span></p>");
                    } elseif ($reservationId !== null) {
                        // ---- Send ---------------------------------------
                        // Omit ring_size so the wallet uses the consensus
                        // default (avoids transfers breaking if a future fork
                        // changes the mandatory ring size).
                        $atomicAmount = (int) ($xmr_payout_amount * 1e12);
                        $transfer = xmr_rpc($rpcUrl, 'transfer', [
                            'destinations'   => [['amount' => $atomicAmount, 'address' => $xmr_address]],
                            'account_index'  => 0,
                            'subaddr_indices' => [0],
                            'priority'       => 0,
                            'get_tx_key'     => true,
                            'unlock_time'    => 0,
                        ], $rpcUser, $rpcPass);

                        if ($transfer !== null && isset($transfer['result']['tx_hash'])) {
                            $txid = $transfer['result']['tx_hash'];
                            $txkey = $transfer['result']['tx_key'] ?? '';
                            // Coins are already sent, so don't let a transient
                            // DB error here turn a success into a 500. Worst case
                            // the row keeps its NULL txid (still blocks reclaim);
                            // stats just undercount until reconciled.
                            try {
                                $db->prepare("UPDATE {$table} SET transaction_id = :txid WHERE id = :id")
                                   ->execute([':txid' => $txid, ':id' => $reservationId]);
                                // Bump the lifetime counter, which the retention
                                // sweep never touches. Distinct placeholders so
                                // we never reuse a named param in one statement.
                                $db->prepare(
                                    "INSERT INTO faucet_totals (net, total_payouts, total_sent)
                                     VALUES (:net, 1, :amt)
                                     ON CONFLICT(net) DO UPDATE SET
                                         total_payouts = total_payouts + 1,
                                         total_sent    = total_sent + :amt2"
                                )->execute([':net' => $nettype, ':amt' => $xmr_payout_amount, ':amt2' => $xmr_payout_amount]);
                            } catch (PDOException $e) {
                                error_log("[xmr-faucet] post-transfer bookkeeping failed for reservation {$reservationId} (tx {$txid}): " . $e->getMessage());
                            }

                            $display_form = "display: none;";
                            $addr_safe = htmlspecialchars($xmr_address, ENT_QUOTES, 'UTF-8');
                            $txid_safe = htmlspecialchars($txid, ENT_QUOTES, 'UTF-8');

                            // Amount + destination, then the txid (monospace, with
                            // a copy button). When an explorer is configured the
                            // txid links to it; otherwise we show the bare hash
                            // plus Monero's payment proof (GUI + CLI verifiable),
                            // so a dev can verify the send without an explorer.
                            $body = "<span>{$xmr_payout_amount} {$currency} has been sent to:</span>"
                                  . "<br/><code class=\"mono\">{$addr_safe}</code>"
                                  . "<br/><br/><span>Transaction ID</span> <button type=\"button\" class=\"copybtn\" data-copy=\"{$txid_safe}\">Copy</button><br/>";

                            if ($explorer_tx !== '') {
                                $explorer_safe = htmlspecialchars($explorer_tx, ENT_QUOTES, 'UTF-8');
                                $body .= "<a href=\"{$explorer_safe}{$txid_safe}\" target=\"_blank\" rel=\"noopener\" id=\"site_link\" class=\"mono\">{$txid_safe}</a>"
                                       . "<br/><br/><span>It may take a minute for the transaction to show up on the explorer.</span>";
                            } else {
                                $body .= "<code class=\"mono\">{$txid_safe}</code>";

                                // Transaction proof: a signature that verifies in
                                // BOTH the Monero GUI (Prove/Check) and the CLI. The
                                // tx key (check_tx_key) is CLI-only, so the raw CLI
                                // commands live under a collapsed "Advanced (CLI)"
                                // section. Best-effort: show what the wallet returns.
                                $proofResp = xmr_rpc($rpcUrl, 'get_tx_proof', [
                                    'txid'    => $txid,
                                    'address' => $xmr_address,
                                ], $rpcUser, $rpcPass);
                                $tx_proof = $proofResp['result']['signature'] ?? '';

                                if ($tx_proof !== '' || $txkey !== '') {
                                    $body .= "<br/><br/><details style=\"margin-top: 5px;\">"
                                           . "<summary style=\"cursor: pointer;\">Payment proof</summary>"
                                           . "<p style=\"margin-top: 8px;\">Verify this payment cryptographically. No blockchain explorer required.</p>";

                                    if ($tx_proof !== '') {
                                        $proof_safe = htmlspecialchars($tx_proof, ENT_QUOTES, 'UTF-8');
                                        $body .= "<p style=\"margin-bottom: 4px;\"><b>Monero GUI:</b><br/>Advanced &rarr; Prove/Check &rarr; Check Transaction</p>"
                                               . "<p style=\"margin-bottom: 0;\">Enter:</p>"
                                               . "<ul style=\"margin-top: 4px;\"><li>Transaction ID (above)</li><li>Recipient address (above)</li><li>Signature (below)</li></ul>"
                                               . "<p><code class=\"mono\">{$proof_safe}</code><br/><button type=\"button\" class=\"copybtn\" data-copy=\"{$proof_safe}\">Copy signature</button></p>";
                                    }

                                    // CLI verification, collapsed (advanced users).
                                    $cli = "";
                                    if ($tx_proof !== '') {
                                        $checkproof = "check_tx_proof {$txid_safe} {$addr_safe} {$proof_safe}";
                                        $cli .= "<p style=\"margin-bottom: 4px;\"><code class=\"mono\">{$checkproof}</code><br/><button type=\"button\" class=\"copybtn\" data-copy=\"{$checkproof}\">Copy</button></p>";
                                    }
                                    if ($txkey !== '') {
                                        $txkey_safe = htmlspecialchars($txkey, ENT_QUOTES, 'UTF-8');
                                        $checkkey = "check_tx_key {$txid_safe} {$txkey_safe} {$addr_safe}";
                                        $cli .= "<p style=\"margin-bottom: 4px;\"><code class=\"mono\">{$checkkey}</code><br/><button type=\"button\" class=\"copybtn\" data-copy=\"{$checkkey}\">Copy</button></p>";
                                    }
                                    if ($cli !== '') {
                                        $body .= "<details style=\"margin-top: 8px;\"><summary style=\"cursor: pointer;\">Advanced (CLI)</summary>"
                                               . "<p style=\"margin-top: 8px; margin-bottom: 4px;\">monero-wallet-cli:</p>" . $cli
                                               . "</details>";
                                    }

                                    $body .= "</details>";
                                }
                            }
                            $active_err = card('success', ' successborder', 'Transaction Status', $body);
                        } elseif ($transfer === null) {
                            // AMBIGUOUS: the RPC didn't answer (timeout/connection
                            // drop). The wallet may or may not have broadcast.
                            // Keep the reservation so a retry can't double-pay;
                            // the worst case is a user locked out for up to an
                            // hour, which is recoverable via /contact.
                            error_log("[xmr-faucet] transfer ambiguous (no RPC response); reservation {$reservationId} kept");
                            $active_err = $error_msg;
                        } else {
                            // DEFINITE failure: the wallet replied with an error
                            // (e.g. insufficient funds). No tx was broadcast, so
                            // release the reservation and let the user retry.
                            try {
                                $db->prepare("DELETE FROM {$table} WHERE id = :id")
                                   ->execute([':id' => $reservationId]);
                            } catch (PDOException $e) {
                                error_log("[xmr-faucet] reservation cleanup failed for {$reservationId}: " . $e->getMessage());
                            }
                            error_log("[xmr-faucet] transfer rejected: " . json_encode($transfer));
                            $active_err = $error_msg;
                        }
                    }
                }
            }
        }
    }
}

// ---- Stats (completed payouts only) -------------------------------------
// Lifetime totals come from faucet_totals, which the retention sweep never
// prunes. If the counter row isn't there yet (fresh install, no payouts), fall
// back to aggregating the live payout table so the page still shows real numbers.
$total_sent    = 0;
$total_payouts = 0;

$aggregate_live = function () use ($db, $table) {
    return $db->query("SELECT COUNT(id) AS total_payouts, SUM(payout_amount) AS total_sent
                       FROM {$table} WHERE transaction_id IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
};

try {
    $stmt = $db->prepare("SELECT total_payouts, total_sent FROM faucet_totals WHERE net = :net");
    $stmt->execute([':net' => $nettype]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: $aggregate_live();
    $total_payouts = (int) ($row['total_payouts'] ?? 0);
    $total_sent    = number_format((float) ($row['total_sent'] ?? 0), 1, '.', '');
} catch (PDOException $e) {
    // faucet_totals doesn't exist yet: aggregate the live table instead.
    try {
        $row = $aggregate_live();
        $total_payouts = (int) ($row['total_payouts'] ?? 0);
        $total_sent    = number_format((float) ($row['total_sent'] ?? 0), 1, '.', '');
    } catch (PDOException $e2) {
        error_log("[xmr-faucet] stats query failed: " . $e2->getMessage());
    }
}

// Two decimals is plenty for a faucet balance (nobody needs picomonero here).
$balance_display = number_format($balance, 2, '.', '');

// Most recent confirmed payout, shown as "x ago" so visitors can see the
// faucet is actively working without an explorer. Recent rows always survive
// the retention sweep. Empty string if there are no payouts yet.
$last_payout = '';
try {
    $r = $db->query("SELECT MAX(timestamp) AS last FROM {$table} WHERE transaction_id IS NOT NULL")
            ->fetch(PDO::FETCH_ASSOC);
    if (!empty($r['last'])) {
        $last_payout = time_ago($r['last']);
    }
} catch (PDOException $e) {
    error_log("[xmr-faucet] last-payout query failed: " . $e->getMessage());
}

// Liveness / sync line, with a status dot so it reads as alive at a glance.
// Falls back step by step: full sync status when the daemon answers, bare
// wallet height when it doesn't, "Unavailable" if the wallet is down.
$node = get_node_status($rpcUrl, $daemon_url, $rpcUser, $rpcPass, dirname($dbFile));
if ($node === null) {
    $dot = '#888';            // gray: wallet unreachable
    $height_display = 'Unavailable';
} elseif ($node['synced'] === true) {
    $dot = '#3fb950';         // green: synced
    $height_display = 'Synced, block ' . number_format($node['height']);
} elseif ($node['synced'] === false) {
    $dot = '#d29922';         // amber: catching up
    $height_display = 'Syncing, ' . number_format($node['height']) . ' / ' . number_format($node['target']);
} else {
    $dot = '#3fb950';         // green: wallet alive, daemon height unknown
    $height_display = 'Block ' . number_format($node['height']);
}
$height_display = "<span style=\"color: {$dot};\">&#9679;</span> " . $height_display;
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <title>CypherFaucet | Monero <?php echo $net_label; ?> Faucet (<?php echo $currency; ?>)</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="/assets/images/monero.png" type="image/png" />
        <link rel="shortcut icon" href="/assets/images/monero.png" type="image/png" />
        <meta name="description" content="Developer friendly Monero <?php echo strtolower($net_label); ?> faucet. Providing developers free <?php echo strtolower($net_label); ?> coins since 2025.">
        <meta name="robots" content="index, follow">
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <meta name="theme-color" content="#c5c5c5">
        <link rel="canonical" href="<?php echo $canonical; ?>" />
        <link rel="stylesheet" type="text/css" href="/assets/style.css?v=6">
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

        <style>
            strong {
                color: #d4d4d4;
            }
            .mono {
                font-family: monospace;
                word-break: break-word;
            }
            .copybtn {
                font-size: 12px;
                padding: 2px 8px;
                margin-left: 4px;
                cursor: pointer;
                vertical-align: middle;
            }
        </style>
    </head>
    <body>
        <nav class="navbar">
            <a href="/">
                <img src="/assets/images/cypherfaucet-banner.png" alt="Logo">
            </a>

            <input type="checkbox" class="menu-toggle" id="menu-toggle" />

            <label for="menu-toggle" class="hamburger">
                <div></div>
                <div></div>
                <div></div>
            </label>

            <div class="nav-links">
                <a href="/">Home</a>
                <a href="/contact">Contact</a>
                <a href="/legal">Legal</a>
            </div>
        </nav>
        <br/>
        <div id="main">
            <span class="title is-size-3 has-icon" style="margin-bottom: 0em !important;">
                <img class="lozad" src="/assets/images/monero.png" alt="Monero <?php echo $net_label; ?>" data-loaded="true" style="height: 28px; display: inline-block; padding-right: 5px;">
                <span style="font-size: 28px; color: #e1e1e1;"><b>Monero <?php echo $net_label; ?> Faucet</b></span>
            </span>
            <br/>
            <span style="font-size: 18px;"><b>Receive <?php echo $xmr_payout_amount; ?> <?php echo $currency; ?> once per hour.</b></span>
            <br/>
            <span>&#9888; The Monero <?php echo $net_label; ?> is used for testing. <?php echo $net_label; ?> funds have no value!</span>

            <div class="card">
                <div class="card-header"><b>Faucet Statistics</b></div>
                <div class="card-body">
                    <span>Balance: <strong><?php echo $balance_display; ?></strong> <?php echo $currency; ?></span><br/>
                    <span>Total Sent: <strong><?php echo $total_sent; ?></strong> <?php echo $currency; ?></span><br/>
                    <span>Total Payouts: <strong><?php echo $total_payouts; ?></strong></span><br/>
<?php if ($last_payout !== '') { ?>                    <span>Last payout: <strong><?php echo $last_payout; ?></strong></span><br/>
<?php } ?>                    <span>Network: <strong><?php echo $height_display; ?></strong></span>
                </div>
            </div>

            <?php echo $active_err; ?>

            <div style="<?php echo $display_form; ?>">
                <div class="card">
                    <div class="card-header"><b>Claim <?php echo $currency; ?></b></div>
                    <div class="card-body">
                        <form method="post" action="">
                            <div class="forminput-wrapper">
                                <input class="forminput" id="address" name="address" aria-label="Monero <?php echo $net_label; ?> address" type="text" value="" placeholder="Your Monero <?php echo $net_label; ?> address (<?php echo $address_hint; ?>)" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" required />
                                <span class="icon-left">
                                    <img src="/assets/images/monero.png" alt="Monero <?php echo $net_label; ?>" />
                                </span>
                            </div>
                            <span style="display: inline-block; margin-top: 8px; font-size: 0.9em;">IP addresses are logged only to prevent faucet abuse.</span>
                            <div class="cf-turnstile" style="margin-top: 12px; margin-bottom: 12px;" data-sitekey="<?php echo TURNSTILE_SITEKEY; ?>" data-theme="dark"></div>
                            <input type="submit" id="send" name="claim" class="formbtn" value="Send <?php echo $currency; ?>" />
                        </form>
                    </div>
                </div>
            </div>

<?php if ($donate_addr !== '') { ?>
            <div class="card">
                <div class="card-header"><b>Please return your unused <?php echo $currency; ?> so others can use them!</b></div>
                <div class="card-body">
                    <p><code class="mono"><?php echo $donate_safe; ?></code> <button type="button" class="copybtn" data-copy="<?php echo $donate_safe; ?>">Copy</button></p>
                    <span><?php echo $currency; ?> sent to this address are forwarded to the faucet periodically.</span>
                </div>
            </div>
<?php } ?>

            <div class="card">
                <div class="card-header">FAQs</div>
                <div class="card-body">
                    <h3>How do I use the faucet?</h3>
                    <p>To use the faucet, enter your Monero <?php echo strtolower($net_label); ?> wallet address, complete the captcha, and click "Send <?php echo $currency; ?>". By using the faucet you agree to our <a href="/legal#terms" id="site_link"><b>Terms and Conditions</b></a>.</p>
                    <h3>What is the Monero <?php echo strtolower($net_label); ?>?</h3>
                    <p>The Monero <?php echo strtolower($net_label); ?> is a separate network which allows developers to test Monero in place of its mainnet counterpart without the need to use coins that have value.</p>
                    <h3>Why don't I see the coins in my wallet or on the explorer?</h3>
                    <p>Sometimes the <?php echo strtolower($net_label); ?> is slow with propagating transactions and the explorers could be running a bit behind. Give it a few minutes and you should be able to see the coins in your wallet (assuming you entered the correct wallet address) and on the explorer via the txid.</p>
                    <h3>The captcha isn't working. What should I do?</h3>
                    <p>This can happen for many reasons. Usually, the captcha could fail if you're using a VPN or ad blocker, unsupported or out-of-date browser.</p>
                    <h3>What's the catch?</h3>
                    <p>There is no catch! I created this and my other faucets to give back to the community.</p>
<?php if ($mainnet_donate !== '') { $mainnet_safe = htmlspecialchars($mainnet_donate, ENT_QUOTES, 'UTF-8'); ?>
                    <h3>Can I support the faucet?</h3>
                    <p>This faucet is free and I run it to give back. If it has saved you time and you would like to help with server costs, <b>mainnet</b> XMR donations are welcome and entirely optional:</p>
                    <p><code class="mono"><?php echo $mainnet_safe; ?></code> <button type="button" class="copybtn" data-copy="<?php echo $mainnet_safe; ?>">Copy</button></p>
<?php } ?>
                </div>
            </div>
            <br/>
            <footer>
                <hr style="width: 17.5%;"/>
                <p style="text-align: center; font-size: 18px;">Established May 5, 2025.<br/>Made with &#9829;&#65039; and &#9749; by <a href="https://tech1k.com" target="_blank" rel="noopener"><b>Tech1k</b></a> &middot; <a href="<?php echo htmlspecialchars($source_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Source</a></p>
            </footer>
        </div>
        <script>
            // Copy-to-clipboard for [data-copy] buttons (txid, check_tx_key
            // command). Graceful: no JS means the text is still selectable.
            document.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-copy]');
                if (!btn) return;
                var text = btn.getAttribute('data-copy');
                var done = function () {
                    var label = btn.textContent;
                    btn.textContent = 'Copied';
                    setTimeout(function () { btn.textContent = label; }, 1500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done, function () {});
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); done(); } catch (err) {}
                    document.body.removeChild(ta);
                }
            });
        </script>
    </body>
</html>
