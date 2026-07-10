-- Node monitoring, runtime settings, seed data

CREATE TABLE node_status (
    chain         text PRIMARY KEY
                  CHECK (chain IN ('bitcoin', 'litecoin', 'ethereum', 'xrp', 'tron')),
    height        bigint NOT NULL DEFAULT 0,
    best_hash     text,
    peers         integer NOT NULL DEFAULT 0,
    synced        boolean NOT NULL DEFAULT false,
    progress      numeric(6,5) NOT NULL DEFAULT 0 CHECK (progress BETWEEN 0 AND 1),
    engine_active boolean NOT NULL DEFAULT false,   -- payment engine gate, flips when synced
    version       text,
    last_error    text,
    updated_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE settings (
    key        text PRIMARY KEY,
    value      jsonb NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER settings_updated_at BEFORE UPDATE ON settings
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ── Seed: supported assets ────────────────────────────────────────────────────
-- dust_threshold in base units: 546 sat (BTC standard dust), 5460 lit,
-- 0.0001 ETH, 0.1 XRP, 0.5 USDT, 0.5 USDC.
INSERT INTO assets (code, chain, display_name, contract_address, decimals, min_confirmations, dust_threshold, invoice_ttl_seconds, enabled) VALUES
    ('BTC',        'bitcoin',  'Bitcoin',       NULL,                                          8, 2,  546,                 3600, true),
    ('LTC',        'litecoin', 'Litecoin',      NULL,                                          8, 6,  5460,                3600, true),
    ('ETH',        'ethereum', 'Ethereum',      NULL,                                         18, 12, 100000000000000,     3600, true),
    ('USDC_ERC20', 'ethereum', 'USD Coin',      '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',   6, 12, 500000,              3600, true),
    ('XRP',        'xrp',      'XRP',           NULL,                                          6, 1,  100000,              3600, true),
    ('USDT_TRC20', 'tron',     'Tether USD',    'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',           6, 19, 500000,              3600, true);

INSERT INTO node_status (chain) VALUES
    ('bitcoin'), ('litecoin'), ('ethereum'), ('xrp'), ('tron');

INSERT INTO settings (key, value) VALUES
    ('sweep.min_batch_base_units', '{"BTC": "100000", "LTC": "1000000", "ETH": "50000000000000000", "USDC_ERC20": "100000000", "XRP": "50000000", "USDT_TRC20": "100000000"}'),
    ('sweep.interval_seconds', '900'),
    ('withdrawal.admin_approval_threshold_base_units', '{"BTC": "50000000", "LTC": "5000000000", "ETH": "5000000000000000000", "USDC_ERC20": "10000000000", "XRP": "10000000000", "USDT_TRC20": "10000000000"}'),
    ('reconciliation.interval_seconds', '300'),
    ('webhook.max_attempts', '10');
