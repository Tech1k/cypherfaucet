<?php
$cfg = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
$source_url = $cfg['source_url'] ?? 'https://github.com/Tech1k/cypherfaucet';
?>
<!-- SPDX-License-Identifier: AGPL-3.0-or-later  |  Copyright (C) 2025-2026 Tech1k -->
<!DOCTYPE html>
<html lang="en">
	<head>
        <title>CypherFaucet | Contact</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="/assets/images/favicon.png" type="image/png" />
        <link rel="shortcut icon" href="/assets/images/favicon.png" type="image/png" />
        <meta name="description" content="Feel free to reach out if you have any inquires.">
        <meta name="robots" content="index, follow">
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <meta name="theme-color" content="#c5c5c5">
        <link rel="canonical" href="https://cypherfaucet.com/contact" />
        <meta property="og:image" content="/assets/images/cypherfaucet-banner.png">
        <meta property="og:image:width" content="955">
        <meta property="og:image:height" content="500">
        <meta property="og:description" content="Feel free to reach out if you have any inquires.">
        <meta property="og:title" content="CypherFaucet | Contact">
        <meta property="og:site_name" content="CypherFaucet">
        <meta property="og:url" content="https://cypherfaucet.com/contact">
        <link rel="stylesheet" type="text/css" href="/assets/style.css?v=4">
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
                <a href="/contact" id="curpage">Contact</a>
                <a href="/legal">Legal</a>
            </div>
        </nav>

		<div id="main">
            <h1>Contact</h1>
            <div>
                <p>Feel free to reach out with any inquires such as issues, questions, or suggestions.</p>
                <div style="margin-bottom: 10px;">Email: <a href="mailto:hello@tech1k.com">hello@tech1k.com</a></div>
                <div style="margin-bottom: 10px;">PGP Fingerprint: <code>5146 46C0 A0FE 2B8A EA84 828A 0C30 342A 9D40 39BD</code></div>
                <div style="margin-bottom: 10px;">PGP Key File: <a href="/tech1k.txt">tech1k.txt</a></div>
                <div>PGP Key:<br/>
                <code>
                    -----BEGIN PGP PUBLIC KEY BLOCK-----
                    <br/><br/>
                    mDMEZ/9jxxYJKwYBBAHaRw8BAQdA0QGWglE0VLF5LWWlR/5gNgZZZYrUStzKqr3s<br/>
                    /XjxGSO0GVRlY2gxayA8aGVsbG9AdGVjaDFrLmNvbT6ImQQTFgoAQRYhBCUhizTS<br/>
                    3sd26ckpkd+CqUGsaxwpBQJn/2PHAhsDBQkFpXhZBQsJCAcCAiICBhUKCQgLAgQW<br/>
                    AgMBAh4HAheAAAoJEN+CqUGsaxwpr2MBANK/OxV3xA7YpD6zLDLMkgLye7Jdubzc<br/>
                    9K2xESi2S6rJAQC+15xR2eBI3qiQ/mJbRQHAhivXfmDRkqXRPofaQG4TBrg4BGf/<br/>
                    Y8cSCisGAQQBl1UBBQEBB0BxU25wK2YPQZGv54SHVophNtHaBfziKAu3QAUKXO/D<br/>
                    WQMBCAeIfgQYFgoAJhYhBCUhizTS3sd26ckpkd+CqUGsaxwpBQJn/2PHAhsMBQkF<br/>
                    pXhZAAoJEN+CqUGsaxwp1HoA/0BjNE17jC/5BpvKxMDwz7bcDXx2aotOqrYZz8bK<br/>
                    /qN2APsGCx01qTkqyWWeOwu0eyLj+nwj2VW0eo9UOR3H39TOCA==<br/>
                    =o3/c<br/>
                    -----END PGP PUBLIC KEY BLOCK-----
                </code>
                </div>
                <br/>
            </div>
            <br/><br/><br/><br/><br/>
            <footer>
                <hr/>
                <p style="text-align: center; font-size: 18px;">Established May 5, 2025.<br/>Made with ♥️ and ☕ by <a href="https://tech1k.com" target="_blank" rel="noopener"><strong>Tech1k</strong></a> &middot; <a href="<?php echo htmlspecialchars($source_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Source</a></p>
            </footer>
        </div>
	</body>
</html>
