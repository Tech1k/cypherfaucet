<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tech1k <hello@tech1k.com>
$cfg = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
$source_url = $cfg['source_url'] ?? 'https://github.com/Tech1k/cypherfaucet';
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <title>CypherFaucet | Page Not Found</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="/assets/images/favicon.png" type="image/png" />
        <link rel="shortcut icon" href="/assets/images/favicon.png" type="image/png" />
        <meta name="robots" content="noindex, follow">
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <meta name="theme-color" content="#c5c5c5">
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
                <a href="/">Home</a>
                <a href="/contact">Contact</a>
                <a href="/legal">Legal</a>
            </div>
        </nav>
        <br/>
        <div id="main">
            <p align="center">
                <span style="font-size: 48px;"><strong>404</strong></span>
                <br/>
                <span style="font-size: 22px;">Page not found.</span>
                <br/><br/>
                <a href="/" id="site_link">Return to the homepage</a>
            </p>
            <br/><br/><br/><br/><br/>
            <footer>
                <hr/>
                <p style="text-align: center; font-size: 18px;">Established May 5, 2025.<br/>Made with ♥️ and ☕ by <a href="https://tech1k.com" target="_blank" rel="noopener"><strong>Tech1k</strong></a> &middot; <a href="<?php echo htmlspecialchars($source_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Source</a></p>
            </footer>
        </div>
    </body>
</html>
