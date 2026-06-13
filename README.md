# CypherFaucet

A small, focused Monero **stagenet** and **testnet** faucet. Developers enter a
dev-net address, complete a captcha, and receive a fixed payout once per hour.
It is the faucet running at [cypherfaucet.com](https://cypherfaucet.com) and
listed on getmonero.org.

Both nets are served by a single file ([xmr-faucet.php](xmr-faucet.php)),
selected by the URL via `.htaccess`.

## Features

- One file, two nets (stagenet + testnet) defined in a config map.
- Cloudflare Turnstile captcha.
- Per-address and per-IP rate limiting (once per hour), serialized so
  concurrent requests can't double-claim.
- Gates on the wallet's **unlocked** balance, so it never offers a claim it
  can't pay (Monero locks outputs for 10 blocks).
- Live wallet/daemon **sync status** and **lifetime** stats (total sent /
  payouts / last payout), kept in a counter table that survives data pruning.
- **Payment proof** on each receipt: a `get_tx_proof` signature that verifies
  in the Monero GUI (Prove/Check) or CLI, plus the `check_tx_key` command.
  Useful when the dev-net block explorers are down.
- IP retention sweep (cron) so claimer IPs are not kept indefinitely.

## Requirements

- PHP 7.4+ with `pdo_sqlite` and `curl`.
- Apache with `mod_rewrite` (and `mod_headers` for the security headers). The
  routing lives in [.htaccess](.htaccess); nginx users must translate it.
- A `monerod` + `monero-wallet-rpc` instance **per net** (stagenet and
  testnet), bound to `127.0.0.1`.
- SQLite 3.24+ (for the `ON CONFLICT` upsert used by the stats counter).

## Setup

1. **Config.** Copy the example and fill it in:
   ```sh
   cp config.example.php config.php
   ```
   Set your Turnstile sitekey/secret, the wallet RPC credentials (or leave
   blank if the wallet uses `--disable-rpc-login` on localhost), and your
   public `source_url`. `config.php` is gitignored; never commit it.

2. **Database.** Create the SQLite file and tables:
   ```sh
   cd db && python3 create_db.py
   ```
   For production, keep the DB **outside the web root** and point the app at it:
   ```sh
   export FAUCET_DB=/var/lib/cypherfaucet/faucet.db
   ```
   (Set it in your vhost / PHP-FPM pool env.) If unset, it defaults to
   `db/faucet.db`. Whichever directory holds the DB must be writable by the web
   server user, because SQLite writes a journal there.

3. **Wallets.** Run `monero-wallet-rpc` for each net on the expected ports
   (stagenet `38088`, testnet `28088`) and the matching `monerod` daemons
   (`38081` / `28081`, used only for the sync-status line). Adjust ports or set
   `daemon_url` per net in the `$FAUCETS` map in
   [xmr-faucet.php](xmr-faucet.php) if your setup differs.

4. **Web server.** Serve the directory with Apache + `mod_rewrite`. The
   `.htaccess` maps `/xmr-stagenet` and `/xmr-testnet` to the app and denies
   web access to `db/`.

5. **Retention cron.** Prune old claim rows (PII) daily:
   ```cron
   17 4 * * * FAUCET_DB=/var/lib/cypherfaucet/faucet.db php /path/to/db/cleanup.php >> /var/log/cypherfaucet-cleanup.log 2>&1
   ```
   Run it as a user that can write the DB and its directory.

## Operating the faucet

Monero locks every output for **10 blocks (~20 min)**, including the change that
comes back from each payout. If the wallet holds one large output, the first
payout locks the rest and claims stall until it unlocks.

Fund the wallet with **many small outputs** (for example ~0.015 each, covering a
0.01 payout plus fee headroom), batch-sent from your collection/donation
address. That keeps a pool of independently spendable outputs ahead of the lock.
The app gates on the unlocked balance, so if the pool is ever exhausted it shows
a "coins locking, try again shortly" message instead of failing a claim.

[`tools/fund_faucet.py`](tools/fund_faucet.py) automates this. Run it on the
machine holding the donation wallet (pointed at that wallet's
`monero-wallet-rpc`), sending to the faucet wallet's receiving address. It
batches the outputs into as few transactions as possible to save fees:

```sh
python3 tools/fund_faucet.py \
    --rpc-url http://127.0.0.1:38083/json_rpc \
    --address <faucet stagenet address> \
    --nettype stagenet \
    --count 50            # 50 outputs of 0.015; add --dry-run to preview
```

It validates the address against the chosen net and checks the donation
wallet's unlocked balance before sending. Stdlib only, no dependencies.

### Automated top-up

For hands-off operation, run it in `--target` mode on a timer. It reads the
faucet wallet's balance and sends only the shortfall, doing nothing when the
faucet is already funded, so it's safe to run often. It targets the faucet's
*total* balance (not unlocked) so it won't overfund while recent top-ups are
still locking.

Because dev-net coins have no value, the simplest setup is to run the donation
wallet's `monero-wallet-rpc` on the faucet server too (bound to `127.0.0.1`, on
its own port) so both wallets are local. If you keep the donation wallet on a
separate machine, point `--faucet-rpc-url` through an SSH tunnel or VPN.

cron, every 15 minutes (one line per net). `flock` prevents overlapping runs if
one ever hangs past the interval:

```cron
*/15 * * * * /usr/bin/flock -n /tmp/cf-topup-stagenet.lock python3 /path/to/tools/fund_faucet.py --rpc-url http://127.0.0.1:38083/json_rpc --faucet-rpc-url http://127.0.0.1:38088/json_rpc --address <faucet stagenet address> --nettype stagenet --target 0.3 --yes >> /var/log/cypherfaucet-topup.log 2>&1
```

(`38083` = the donation wallet's RPC; `38088` = the faucet wallet's RPC. Add a
second line with the testnet ports/address and its own lock file for the testnet
faucet.)

Or a systemd timer:

```ini
# /etc/systemd/system/cypherfaucet-topup.service
[Service]
Type=oneshot
ExecStart=/usr/bin/python3 /path/to/tools/fund_faucet.py --rpc-url http://127.0.0.1:38083/json_rpc --faucet-rpc-url http://127.0.0.1:38088/json_rpc --address <faucet stagenet address> --nettype stagenet --target 0.3 --yes

# /etc/systemd/system/cypherfaucet-topup.timer
[Timer]
OnCalendar=*:0/15
Persistent=true
[Install]
WantedBy=timers.target
```

Enable with `systemctl enable --now cypherfaucet-topup.timer`. (systemd won't
start a second run while one is still active, so no lock file is needed here.)

## Security notes

- Keep `config.php` and the SQLite DB out of the repo and out of the web root.
- Bind `monero-wallet-rpc` to `127.0.0.1` (or use real `--rpc-login` creds). A
  reachable, unauthenticated wallet RPC can be drained directly.
- On SELinux systems (Fedora/RHEL), the DB directory needs the
  `httpd_sys_rw_content_t` context for Apache to write it.
- Rate limiting trusts `CF-Connecting-IP` (falling back to `REMOTE_ADDR`);
  `X-Forwarded-For` / `Client-IP` are ignored. For that header to be
  unspoofable, the origin must only be reachable through Cloudflare: firewall it
  to Cloudflare's IP ranges (https://www.cloudflare.com/ips) and/or enable
  Authenticated Origin Pulls. Otherwise a direct-to-origin request can forge it.

## License

Copyright (C) 2025-2026 Tech1k

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU Affero General Public License as published by the Free
Software Foundation, either version 3 of the License, or (at your option) any
later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License along
with this program. If not, see <https://www.gnu.org/licenses/>.

### Forking

If you run your own instance, change the operator-specific bits (they're
hardcoded, not in config): the domain in the `canonical` / `og:` / `twitter:`
meta and in `sitemap.xml` / `robots.txt`, the footer attribution, the operator
and contact details in `legal.php`, and the PGP identity in `contact.php`,
`SECURITY.md`, and `tech1k.txt`. Secrets, RPC creds, and donation addresses are
already in `config.php`.

Issues and pull requests are welcome.
