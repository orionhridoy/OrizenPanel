-- Assets, wallets, address pool
-- All amounts across the schema are integers in the asset's BASE UNIT
-- (satoshi, litoshi, wei, drops, TRC20/ERC20 token base units): NUMERIC(38,0).

CREATE TABLE assets (
    code               text PRIMARY KEY,                 -- BTC, LTC, ETH, XRP, USDT_TRC20, USDC_ERC20
    chain              text NOT NULL
                       CHECK (chain IN ('bitcoin', 'litecoin', 'ethereum', 'xrp', 'tron')),
    display_name       text NOT NULL,
    contract_address   text,                             -- ERC20 / TRC20 only
    decimals           integer NOT NULL CHECK (decimals BETWEEN 0 AND 18),
    min_confirmations  integer NOT NULL CHECK (min_confirmations >= 1),
    dust_threshold     numeric(38,0) NOT NULL DEFAULT 0, -- ignore deposits below (dust-attack filter)
    invoice_ttl_seconds integer NOT NULL DEFAULT 900,
    enabled            boolean NOT NULL DEFAULT true,
    updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER assets_updated_at BEFORE UPDATE ON assets
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE wallets (
    id               uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    asset_code       text NOT NULL REFERENCES assets(code),
    type             text NOT NULL CHECK (type IN
                     ('DEPOSIT_HD', 'WATCH_ONLY', 'TREASURY', 'HOT', 'WARM', 'COLD', 'MULTISIG')),
    name             text NOT NULL,
    -- account-level EXTENDED PUBLIC key only; private material lives in the signer
    xpub             text,
    derivation_path  text,                               -- e.g. m/84'/0'/0'
    address          text,                               -- single-address wallets (TREASURY/HOT/WARM/COLD)
    destination_tag_space bigint,                        -- XRP: next destination tag (tag-per-invoice)
    next_index       bigint NOT NULL DEFAULT 0,          -- HD child index allocator
    multisig_m       integer,
    multisig_n       integer,
    metadata         jsonb NOT NULL DEFAULT '{}',
    is_active        boolean NOT NULL DEFAULT true,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT hd_needs_xpub CHECK (type NOT IN ('DEPOSIT_HD', 'WATCH_ONLY') OR xpub IS NOT NULL),
    CONSTRAINT single_addr_wallets CHECK (type IN ('DEPOSIT_HD', 'WATCH_ONLY', 'MULTISIG') OR address IS NOT NULL),
    CONSTRAINT multisig_shape CHECK (
        type <> 'MULTISIG' OR (multisig_m IS NOT NULL AND multisig_n IS NOT NULL
                               AND multisig_m > 0 AND multisig_m <= multisig_n)
    )
);
CREATE TRIGGER wallets_updated_at BEFORE UPDATE ON wallets
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
-- one active deposit wallet and one active treasury per asset
CREATE UNIQUE INDEX wallets_one_active_deposit ON wallets (asset_code)
    WHERE type = 'DEPOSIT_HD' AND is_active;
CREATE UNIQUE INDEX wallets_one_active_treasury ON wallets (asset_code)
    WHERE type = 'TREASURY' AND is_active;

CREATE TABLE wallet_addresses (
    id                uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    wallet_id         uuid NOT NULL REFERENCES wallets(id),
    asset_code        text NOT NULL REFERENCES assets(code),
    derivation_index  bigint,
    address           text NOT NULL,
    destination_tag   bigint,                            -- XRP invoices
    script_metadata   jsonb NOT NULL DEFAULT '{}',       -- scriptPubKey / pubkey info for sweeps
    is_used           boolean NOT NULL DEFAULT false,    -- permanently consumed once assigned (no reuse)
    created_at        timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX wallet_addresses_unique
    ON wallet_addresses (asset_code, address, destination_tag) NULLS NOT DISTINCT;
CREATE INDEX wallet_addresses_wallet_idx ON wallet_addresses (wallet_id, derivation_index);
CREATE INDEX wallet_addresses_lookup_idx ON wallet_addresses (address);
