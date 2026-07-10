-- Store-credit / top-up wallet subsystem.
--
-- End-users of a merchant's store get a per-user, per-asset balance tracked in
-- the SAME immutable double-entry ledger (new STORE_USER_AVAILABLE account
-- type). Top-ups credit the user; purchases move credit user → merchant.

-- ── store users (customers of a merchant's store) ────────────────────────────
CREATE TABLE store_users (
    id           uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    merchant_id  uuid NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
    external_ref text NOT NULL,                 -- the store's own customer id
    display_name text,
    email        citext,
    metadata     jsonb NOT NULL DEFAULT '{}',
    created_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (merchant_id, external_ref)
);
CREATE INDEX store_users_merchant_idx ON store_users (merchant_id);

-- ── merchant store settings ──────────────────────────────────────────────────
ALTER TABLE merchants ADD COLUMN store_enabled boolean NOT NULL DEFAULT false;
ALTER TABLE merchants ADD COLUMN store_name    text;

-- ── invoices: link to a store user + mark top-up purpose ────────────────────
ALTER TABLE invoices ADD COLUMN store_user_id uuid REFERENCES store_users(id);
ALTER TABLE invoices ADD COLUMN purpose text NOT NULL DEFAULT 'MERCHANT'
    CHECK (purpose IN ('MERCHANT', 'TOPUP'));
CREATE INDEX invoices_store_user_idx ON invoices (store_user_id) WHERE store_user_id IS NOT NULL;

-- ── ledger: per-store-user accounts ──────────────────────────────────────────
ALTER TABLE ledger_accounts ADD COLUMN store_user_id uuid REFERENCES store_users(id);

-- allow the new account type
ALTER TABLE ledger_accounts DROP CONSTRAINT ledger_accounts_type_check;
ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type_check CHECK (type IN (
    'MERCHANT_AVAILABLE', 'MERCHANT_PENDING', 'MERCHANT_LOCKED',
    'GATEWAY_FEES', 'GATEWAY_TREASURY', 'EXTERNAL_DEPOSITS', 'EXTERNAL_WITHDRAWALS',
    'STORE_USER_AVAILABLE'
));

-- allow the store-purchase journal type (customer balance → merchant balance)
ALTER TABLE ledger_journal DROP CONSTRAINT ledger_journal_journal_type_check;
ALTER TABLE ledger_journal ADD CONSTRAINT ledger_journal_journal_type_check CHECK (journal_type IN (
    'PAYMENT_CONFIRMED', 'PAYMENT_REVERSED', 'SETTLEMENT', 'SWEEP',
    'WITHDRAWAL_LOCK', 'WITHDRAWAL_EXECUTE', 'WITHDRAWAL_RELEASE',
    'FEE', 'ADJUSTMENT', 'STORE_PURCHASE'
));

-- shape: merchant accounts (no store user), store-user accounts (both), system (neither)
ALTER TABLE ledger_accounts DROP CONSTRAINT merchant_account_shape;
ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_account_shape CHECK (
    (type LIKE 'MERCHANT%'    AND merchant_id IS NOT NULL AND store_user_id IS NULL)
    OR (type LIKE 'STORE_USER%'  AND merchant_id IS NOT NULL AND store_user_id IS NOT NULL)
    OR (type NOT LIKE 'MERCHANT%' AND type NOT LIKE 'STORE_USER%'
        AND merchant_id IS NULL AND store_user_id IS NULL)
);

-- uniqueness now includes the store user
DROP INDEX ledger_accounts_unique;
CREATE UNIQUE INDEX ledger_accounts_unique
    ON ledger_accounts (merchant_id, store_user_id, asset_code, type) NULLS NOT DISTINCT;

-- ── store purchases (spend records) ──────────────────────────────────────────
CREATE TABLE store_purchases (
    id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    merchant_id     uuid NOT NULL REFERENCES merchants(id),
    store_user_id   uuid NOT NULL REFERENCES store_users(id),
    asset_code      text NOT NULL REFERENCES assets(code),
    amount          numeric(38,0) NOT NULL CHECK (amount > 0),
    description     text,
    idempotency_key text,
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX store_purchases_idem
    ON store_purchases (merchant_id, idempotency_key) WHERE idempotency_key IS NOT NULL;
CREATE INDEX store_purchases_user_idx ON store_purchases (store_user_id, created_at DESC);
