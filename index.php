<?php
$cfg = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
$source_url = $cfg['source_url'] ?? 'https://github.com/Tech1k/cypherfaucet';
?>
<!-- SPDX-License-Identifier: AGPL-3.0-or-later  |  Copyright (C) 2025-2026 Tech1k -->
<!DOCTYPE html>
<html lang="en">
    <head>
        <title>CypherFaucet | Monero Testnet &amp; Stagenet Faucet</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="/assets/images/favicon.png" type="image/png" />
        <link rel="shortcut icon" href="/assets/images/favicon.png" type="image/png" />
        <meta name="description" content="Free Monero testnet and stagenet coins for developers testing applications.">
        <meta name="robots" content="index, follow">
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <meta name="theme-color" content="#c5c5c5">
        <link rel="canonical" href="https://cypherfaucet.com" />
        <meta property="og:image" content="/assets/images/cypherfaucet-banner.png">
        <meta property="og:image:width" content="955">
        <meta property="og:image:height" content="500">
        <meta property="og:description" content="Free Monero testnet and stagenet coins for developers testing applications.">
        <meta property="og:title" content="CypherFaucet">
        <meta property="og:site_name" content="CypherFaucet">
        <meta property="og:url" content="https://cypherfaucet.com">
        <link rel="stylesheet" type="text/css" href="/assets/style.css?v=6">
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
                <a href="/" id="curpage">Home</a>
                <a href="/contact">Contact</a>
                <a href="/legal">Legal</a>
            </div>
        </nav>

        <br/><br/><br/>

        <div id="main">
            <p align="center">
                <img src="/assets/images/cypherfaucet-icon.png" height="128px" alt="CypherFaucet Icon">
                <br/>
                <span style="font-size: 48px;"><strong>CypherFaucet</strong></span>
                <br/>
                <span style="font-size: 32px;">Monero testnet and stagenet faucets.</span>
            </p>

            <div style="display: flex; flex-direction: column; align-items: center; gap: 12px;">
                <div style="display: flex; align-items: center; margin-top: 10px;">
                    <img src="/assets/images/monero.png" width="32px" style="margin-right: 8px;">
                    <div>
                        <a href="/xmr-stagenet" style="text-decoration: none; font-size: 18px;">Monero Stagenet Faucet</a><br>
                        <small style="font-size: 15px;">Get 0.01 sXMR every hour</small>
                    </div>
                </div>

                <div style="display: flex; align-items: center; margin-top: 10px;">
                    <img src="/assets/images/monero.png" width="32px" style="margin-right: 8px;">
                    <div>
                        <a href="/xmr-testnet" style="text-decoration: none; font-size: 18px;">Monero Testnet Faucet</a><br>
                        <small style="font-size: 15px;">Get 0.01 tXMR every hour</small>
                    </div>
                </div>
            </div>
            <br/><br/><br/><br/><br/>
            <footer>
                <hr/>
                <p style="text-align: center; font-size: 18px;">Established May 5, 2025.<br/>Made with ♥️ and ☕ by <a href="https://tech1k.com" target="_blank" rel="noopener"><strong>Tech1k</strong></a> &middot; <a href="<?php echo htmlspecialchars($source_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Source</a></p>
            </footer>
        </div>
    </body>
</html>
