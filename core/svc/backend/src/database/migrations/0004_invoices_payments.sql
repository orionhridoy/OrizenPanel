-- Invoices, detected payments, chain tip tracking (reorg detection)

CREATE TABLE invoices (
    id                    uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    merchant_id           uuid NOT NULL REFERENCES merchants(id),
    order_id              text,                          -- merchant's own reference
    asset_code            text NOT NULL REFERENCES assets(code),
    amount_due            numeric(38,0) NOT NULL CHECK (amount_due > 0),
    amount_paid_pending   numeric(38,0) NOT NULL DEFAULT 0,  -- seen in mempool / confirming
    amount_paid_confirmed numeric(38,0) NOT NULL DEFAULT 0,  -- fully confirmed
    address_id            uuid NOT NULL REFERENCES wallet_addresses(id),
    status                text NOT NULL DEFAULT 'NEW' CHECK (status IN
                          ('NEW', 'SEEN', 'CONFIRMING', 'PAID', 'UNDERPAID', 'OVERPAID', 'EXPIRED', 'INVALID')),
    underpayment_tolerance_bps integer NOT NULL DEFAULT 100,
    required_confirmations integer NOT NULL,             -- snapshot from assets at creation
    description           text,
    metadata              jsonb NOT NULL DEFAULT '{}',   -- merchant free-form (fiat display info etc.)
    redirect_url          text,
    expires_at            timestamptz NOT NULL,
    paid_at               timestamptz,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER invoices_updated_at BEFORE UPDATE ON invoices
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE UNIQUE INDEX invoices_merchant_order_unique ON invoices (merchant_id, order_id)
    WHERE order_id IS NOT NULL;
CREATE UNIQUE INDEX invoices_address_unique ON invoices (address_id);  -- address never reused
CREATE INDEX invoices_merchant_created_idx ON invoices (merchant_id, created_at DESC);
CREATE INDEX invoices_open_idx ON invoices (status, expires_at)
    WHERE status IN ('NEW', 'SEEN', 'CONFIRMING');

CREATE TABLE payments (
    id               uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    invoice_id       uuid REFERENCES invoices(id),       -- NULL: unsolicited deposit to a known address
    address_id       uuid NOT NULL REFERENCES wallet_addresses(id),
    asset_code       text NOT NULL REFERENCES assets(code),
    txid             text NOT NULL,
    output_index     integer,                            -- UTXO chains: vout
    log_index        integer,                            -- EVM token transfers
    amount           numeric(38,0) NOT NULL CHECK (amount > 0),
    from_address     text,
    status           text NOT NULL DEFAULT 'MEMPOOL' CHECK (status IN
                     ('MEMPOOL', 'CONFIRMING', 'CONFIRMED', 'ORPHANED', 'REPLACED', 'REJECTED')),
    block_height     bigint,
    block_hash       text,
    confirmations    integer NOT NULL DEFAULT 0,
    is_rbf           boolean NOT NULL DEFAULT false,     -- signals replaceability while unconfirmed
    replaced_by_txid text,
    credited         boolean NOT NULL DEFAULT false,     -- ledger journal posted
    detected_at      timestamptz NOT NULL DEFAULT now(),
    confirmed_at     timestamptz,
    updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER payments_updated_at BEFORE UPDATE ON payments
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
-- duplicate-payment guard: one row per on-chain output/event
CREATE UNIQUE INDEX payments_txout_unique
    ON payments (asset_code, txid, output_index, log_index) NULLS NOT DISTINCT;
CREATE INDEX payments_invoice_idx ON payments (invoice_id);
CREATE INDEX payments_watch_idx ON payments (asset_code, status)
    WHERE status IN ('MEMPOOL', 'CONFIRMING');
CREATE INDEX payments_block_idx ON payments (asset_code, block_height);

-- Rolling window of recent block hashes per chain; a parent-hash mismatch on
-- extension marks a reorganization and triggers re-validation of payments.
CREATE TABLE chain_tips (
    chain       text NOT NULL,
    height      bigint NOT NULL,
    block_hash  text NOT NULL,
    parent_hash text NOT NULL,
    scanned_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (chain, height)
);
