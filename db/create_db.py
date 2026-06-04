# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2025-2026 Tech1k <hello@tech1k.com>
# Script to create faucet sqlite database

import sqlite3

db_file = 'faucet.db'

db = sqlite3.connect(db_file)

cursor = db.cursor()

create_sxmr = '''
CREATE TABLE IF NOT EXISTS xmr_stagenet_payouts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payout_address TEXT NOT NULL,
    ip_address TEXT NOT NULL,
    payout_amount REAL NOT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    transaction_id TEXT,
    UNIQUE (payout_address, timestamp)
);
'''

create_txmr = '''
CREATE TABLE IF NOT EXISTS xmr_testnet_payouts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payout_address TEXT NOT NULL,
    ip_address TEXT NOT NULL,
    payout_amount REAL NOT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    transaction_id TEXT,
    UNIQUE (payout_address, timestamp)
);
'''

# Lifetime stats counter, keyed by net. Kept separate from the payout rows so
# the retention sweep can prune old claims without resetting "Total Sent".
create_totals = '''
CREATE TABLE IF NOT EXISTS faucet_totals (
    net TEXT PRIMARY KEY,
    total_payouts INTEGER NOT NULL DEFAULT 0,
    total_sent REAL NOT NULL DEFAULT 0
);
'''

cursor.execute(create_sxmr)
cursor.execute(create_txmr)
cursor.execute(create_totals)

# Indexes for the rate-limit lookup, which filters on
# (payout_address OR ip_address) within a time window. The UNIQUE constraint
# already provides a (payout_address, timestamp) index; add (ip_address,
# timestamp) so the IP branch isn't a full table scan on a busy faucet.
indexes = [
    "CREATE INDEX IF NOT EXISTS idx_xmr_stagenet_ip_ts  ON xmr_stagenet_payouts(ip_address, timestamp);",
    "CREATE INDEX IF NOT EXISTS idx_xmr_testnet_ip_ts   ON xmr_testnet_payouts(ip_address, timestamp);",
]
for stmt in indexes:
    cursor.execute(stmt)

db.commit()
db.close()

print("Database initialized and tables xmr_stagenet_payouts, xmr_testnet_payouts, faucet_totals created successfully.")
