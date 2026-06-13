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
        <meta property="og:image" content="https://cypherfaucet.com/assets/images/og-banner.png">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="https://cypherfaucet.com/assets/images/og-banner.png">
        <meta property="og:description" content="Feel free to reach out if you have any inquires.">
        <meta property="og:title" content="CypherFaucet | Contact">
        <meta property="og:site_name" content="CypherFaucet">
        <meta property="og:url" content="https://cypherfaucet.com/contact">
        <link rel="stylesheet" type="text/css" href="/assets/style.css?v=9">
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
                <div style="margin-bottom: 10px;">PGP Fingerprint: <code>D62A E4C5 3E95 2FDC 967C 0A65 5E51 FE48 287E ED4B</code></div>
                <div style="margin-bottom: 10px;">PGP Key File: <a href="/tech1k.txt">tech1k.txt</a></div>
                <div>PGP Key:<br/>
                <code>
                -----BEGIN PGP PUBLIC KEY BLOCK-----
                <br/><br/>
                mDMEaiS1MBYJKwYBBAHaRw8BAQdAeW6l1NKkJ/oPOqZIBnl9PcRAWAs2KRJLYA04<br/>
                yq8tBzO0GVRlY2gxayA8aGVsbG9AdGVjaDFrLmNvbT6ImQQTFgoAQRYhBNYq5MU+<br/>
                lS/clnwKZV5R/kgofu1LBQJqJLUwAhsDBQkFpJbwBQsJCAcCAiICBhUKCQgLAgQW<br/>
                AgMBAh4HAheAAAoJEF5R/kgofu1L0SYA/RvstakXgEaUyyh867ehNNzPBAa+qr9W<br/>
                iIiL67xmf5/QAQDzfkQs4M9AEFH9NFrXUUlyE9EaNb+ak/keUWlNyfrKA7g4BGok<br/>
                tTASCisGAQQBl1UBBQEBB0BXqSOypMHpPtEB5Y6hWLBOViWqbqK7hMKk8sNlEysF<br/>
                LwMBCAeIfgQYFgoAJhYhBNYq5MU+lS/clnwKZV5R/kgofu1LBQJqJLUwAhsMBQkF<br/>
                pJbwAAoJEF5R/kgofu1LcNABAI3dTDmnq7jAAK0EqXeb7wHy+HBVWKm+ZAdo3M9r<br/>
                wJviAQCLMZhf8gS+rNPef1oVR8i+UJlhiyJWIg2X9o9YEFMPCA==<br/>
                =DMDG<br/>
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
