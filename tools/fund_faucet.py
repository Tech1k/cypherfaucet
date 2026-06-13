#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2025-2026 Tech1k <hello@tech1k.com>
"""
fund_faucet.py - top up a CypherFaucet wallet with many small, independently
spendable outputs.

Monero locks every output for 10 blocks (~20 min), including the change from
each payout. A faucet holding one large output stalls after every send while
its change is locked. Funding it with many small outputs (default 0.015 each,
enough for a 0.01 payout plus fee) keeps a pool of spendable outputs ahead of
the lock.

Run this on the machine holding the DONATION wallet (where returned coins
arrive), pointed at that wallet's monero-wallet-rpc, sending to the FAUCET
wallet's receiving address. Outputs are batched into as few transactions as
possible (default 15 destinations per tx) to minimise fees, while still landing
as separate spendable outputs in the faucet wallet.

Example:
  python3 fund_faucet.py \\
      --rpc-url http://127.0.0.1:38083/json_rpc \\
      --address <faucet stagenet address> \\
      --nettype stagenet \\
      --count 50

Dry run first to see the plan without sending:
  python3 fund_faucet.py ... --count 50 --dry-run

Automated top-up (for cron): keep the faucet at >= 0.3 XMR total, sending only
the shortfall and doing nothing when it's already funded:
  python3 fund_faucet.py \\
      --rpc-url http://127.0.0.1:38083/json_rpc \\
      --faucet-rpc-url http://127.0.0.1:38088/json_rpc \\
      --address <faucet stagenet address> --nettype stagenet \\
      --target 0.3 --yes

Stdlib only; no third-party dependencies.
"""

import argparse
import json
import sys
import urllib.request

ATOMIC = 10 ** 12  # piconero per XMR
# Monero allows at most 16 outputs per tx; leave room for the change output.
MAX_DESTS_PER_TX = 15
# Rough per-tx fee headroom for the pre-send balance check. Real priority-0
# dev-net fees are far smaller; this just stops the last tx failing when the
# donation balance is an exact multiple of the output amount.
FEE_HEADROOM = 10 ** 8  # 0.0001 XMR per tx


def make_opener(url, user, password):
    """Build a urllib opener, with HTTP digest auth if credentials are given."""
    if not user:
        return urllib.request.build_opener()
    mgr = urllib.request.HTTPPasswordMgrWithDefaultRealm()
    mgr.add_password(None, url, user, password or "")
    return urllib.request.build_opener(urllib.request.HTTPDigestAuthHandler(mgr))


def rpc(opener, url, method, params=None, timeout=120):
    body = json.dumps(
        {"jsonrpc": "2.0", "id": "0", "method": method, "params": params or {}}
    ).encode()
    req = urllib.request.Request(
        url, data=body, headers={"Content-Type": "application/json"}
    )
    with opener.open(req, timeout=timeout) as resp:
        data = json.loads(resp.read().decode())
    if data.get("error"):
        raise RuntimeError(f"RPC {method} error: {data['error']}")
    return data.get("result", {})


def xmr(atomic):
    return f"{atomic / ATOMIC:.12f}".rstrip("0").rstrip(".")


def main():
    ap = argparse.ArgumentParser(
        description="Fund a CypherFaucet wallet with many small outputs.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    ap.add_argument("--rpc-url", required=True,
                    help="Donation wallet's monero-wallet-rpc URL, e.g. http://127.0.0.1:38083/json_rpc")
    ap.add_argument("--rpc-user", default=None, help="RPC username (if --rpc-login is set)")
    ap.add_argument("--rpc-pass", default=None, help="RPC password (if --rpc-login is set)")
    ap.add_argument("--address", required=True, help="Faucet wallet receiving address to fund")
    ap.add_argument("--nettype", required=True, choices=["stagenet", "testnet", "mainnet"],
                    help="Network the faucet address must belong to")
    ap.add_argument("--amount", type=float, default=0.015,
                    help="XMR per output (should exceed the faucet payout + fee)")
    grp = ap.add_mutually_exclusive_group(required=True)
    grp.add_argument("--count", type=int, help="Manual: number of outputs to create")
    grp.add_argument("--total", type=float, help="Manual: total XMR to send (count = total / amount)")
    grp.add_argument("--target", type=float,
                     help="Top-up mode: keep the faucet wallet's total balance at or above this "
                          "many XMR, sending only the shortfall (requires --faucet-rpc-url)")
    ap.add_argument("--faucet-rpc-url", default=None,
                    help="Faucet wallet's monero-wallet-rpc URL (read-only balance check, for --target)")
    ap.add_argument("--faucet-rpc-user", default=None, help="Faucet wallet RPC username")
    ap.add_argument("--faucet-rpc-pass", default=None, help="Faucet wallet RPC password")
    ap.add_argument("--max-outputs", type=int, default=100,
                    help="Safety cap on outputs created in a single run")
    ap.add_argument("--per-tx", type=int, default=MAX_DESTS_PER_TX,
                    help=f"Destinations per transaction (max {MAX_DESTS_PER_TX})")
    ap.add_argument("--priority", type=int, default=0, choices=[0, 1, 2, 3],
                    help="Transfer priority (0 = default/slowest/cheapest)")
    ap.add_argument("--dry-run", action="store_true", help="Show the plan; send nothing")
    ap.add_argument("--yes", action="store_true", help="Skip the confirmation prompt")
    args = ap.parse_args()

    if args.amount <= 0:
        ap.error("--amount must be positive")
    per_tx = max(1, min(args.per_tx, MAX_DESTS_PER_TX))
    amount_atomic = int(round(args.amount * ATOMIC))

    opener = make_opener(args.rpc_url, args.rpc_user, args.rpc_pass)

    # Resolve how many outputs to create.
    if args.target is not None:
        if not args.faucet_rpc_url:
            ap.error("--target requires --faucet-rpc-url (to read the faucet's balance)")
        f_opener = make_opener(args.faucet_rpc_url, args.faucet_rpc_user, args.faucet_rpc_pass)
        try:
            faucet_total = rpc(f_opener, args.faucet_rpc_url, "get_balance").get("balance", 0)
        except Exception as e:
            sys.exit(f"Could not read the faucet wallet balance: {e}")
        # Target TOTAL balance, not unlocked: outputs just sent are locked for
        # ~20 min, so targeting total stops repeated cron runs from overfunding
        # while recent top-ups are still locking.
        deficit = int(round(args.target * ATOMIC)) - faucet_total
        if deficit <= 0:
            print(f"Faucet balance {xmr(faucet_total)} XMR is at or above the {args.target} XMR "
                  f"target. Nothing to send.")
            return
        count = -(-deficit // amount_atomic)  # ceil division
        print(f"Faucet balance {xmr(faucet_total)} XMR, target {args.target} XMR: "
              f"topping up {count} output(s).")
    elif args.count is not None:
        count = args.count
    else:
        count = int(round(args.total * ATOMIC)) // amount_atomic

    if count <= 0:
        sys.exit("Nothing to send (resolved to 0 outputs).")
    if count > args.max_outputs:
        print(f"Note: needed {count} outputs; capping this run at {args.max_outputs} "
              f"(--max-outputs). Rerun to continue.")
        count = args.max_outputs

    # Make sure we're talking to the right wallet, on the right net.
    try:
        rpc(opener, args.rpc_url, "refresh")  # best-effort; ignore if unsupported
    except Exception:
        pass

    try:
        v = rpc(opener, args.rpc_url, "validate_address",
                {"address": args.address, "any_net_type": True})
    except Exception as e:
        sys.exit(f"Could not reach the wallet RPC: {e}")
    if not v.get("valid"):
        sys.exit("Faucet address is not a valid Monero address.")
    if v.get("nettype") != args.nettype:
        sys.exit(f"Address is for '{v.get('nettype')}', but --nettype is '{args.nettype}'. Aborting.")

    num_tx = (count + per_tx - 1) // per_tx
    bal = rpc(opener, args.rpc_url, "get_balance")
    unlocked = bal.get("unlocked_balance", 0)
    needed = amount_atomic * count
    fee_buffer = num_tx * FEE_HEADROOM
    if unlocked < needed + fee_buffer:
        sys.exit(f"Donation wallet unlocked balance {xmr(unlocked)} XMR is less than the "
                 f"~{xmr(needed + fee_buffer)} XMR needed for {count} x {args.amount} plus fees. Aborting.")
    print(f"Network:        {args.nettype}")
    print(f"Faucet address: {args.address}")
    print(f"Outputs:        {count} x {args.amount} XMR  = {xmr(needed)} XMR")
    print(f"Transactions:   {num_tx} (up to {per_tx} outputs each), priority {args.priority}")
    print(f"Unlocked bal:   {xmr(unlocked)} XMR")

    if args.dry_run:
        print("\n[dry-run] Nothing sent.")
        return
    if not args.yes:
        if input("\nProceed? [y/N] ").strip().lower() not in ("y", "yes"):
            print("Aborted.")
            return

    remaining = count
    sent = 0
    total_fee = 0
    while remaining > 0:
        n = min(per_tx, remaining)
        dests = [{"amount": amount_atomic, "address": args.address} for _ in range(n)]
        try:
            res = rpc(opener, args.rpc_url, "transfer", {
                "destinations": dests,
                "account_index": 0,
                "priority": args.priority,
                "get_tx_key": False,
            })
        except Exception as e:
            print(f"\nTransaction failed after {sent} output(s): {e}", file=sys.stderr)
            sys.exit(1)
        fee = res.get("fee", 0)
        total_fee += fee
        sent += n
        print(f"  sent {n:>3} output(s)  tx {res.get('tx_hash', '?')}  fee {xmr(fee)} XMR  ({sent}/{count})")
        remaining -= n

    print(f"\nDone. Created {sent} outputs ({xmr(amount_atomic * sent)} XMR) "
          f"in {num_tx} tx, total fees {xmr(total_fee)} XMR.")
    print("Outputs unlock after 10 confirmations (~20 min).")


if __name__ == "__main__":
    main()
