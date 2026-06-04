<?php
// Copy this file to config.php and fill in your values.
// config.php is gitignored and must NOT be committed (it holds secrets).
return [
    // Cloudflare Turnstile keys (https://dash.cloudflare.com -> Turnstile).
    // The sitekey is public; the secret must stay private.
    'turnstile_secret'  => 'YOUR_TURNSTILE_SECRET',
    'turnstile_sitekey' => 'YOUR_TURNSTILE_SITEKEY',

    // monero-wallet-rpc credentials (--rpc-login user:pass). Leave blank if the
    // wallet runs with --disable-rpc-login bound to 127.0.0.1.
    'rpc_user' => '',
    'rpc_pass' => '',

    // Public link to your source, shown in the footer to satisfy AGPL section 13.
    'source_url' => 'https://github.com/youruser/cypherfaucet',
];
