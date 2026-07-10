-- Immutable double-entry ledger.
--
-- Conventions:
--  * Every journal must balance: SUM(debits) == SUM(credits) per asset
--    (enforced by a deferred constraint trigger).
--  * account_balances.balance is signed CREDIT-minus-DEBIT, maintained ONLY by
--    the ledger_entries insert trigger - never written by application code.
--  * ledger_journal / ledger_entries are append-only (trigger + REVOKE).

CREATE TABLE ledger_accounts (
    id           uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    merchant_id  uuid REFERENCES merchants(id),          -- NULL for gateway/system accounts
    asset_code   text NOT NULL REFERENCES assets(code),
    type         text NOT NULL CHECK (type IN (
                 'MERCHANT_AVAILABLE',  -- withdrawable
                 'MERCHANT_PENDING',    -- confirmed but held (HOLD/SCHEDULED settlement)
                 'MERCHANT_LOCKED',     -- reserved by an in-flight withdrawal
                 'GATEWAY_FEES',
                 'GATEWAY_TREASURY',    -- funds swept to treasury custody
                 'EXTERNAL_DEPOSITS',   -- on-chain inflow counter-account
                 'EXTERNAL_WITHDRAWALS' -- on-chain outflow counter-account
                 )),
    created_at   timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT merchant_account_shape CHECK (
        (type LIKE 'MERCHANT%' AND merchant_id IS NOT NULL)
        OR (type NOT LIKE 'MERCHANT%' AND merchant_id IS NULL)
    )
);
CREATE UNIQUE INDEX ledger_accounts_unique
    ON ledger_accounts (merchant_id, asset_code, type) NULLS NOT DISTINCT;

CREATE TABLE ledger_journal (
    id             uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    journal_type   text NOT NULL CHECK (journal_type IN (
                   'PAYMENT_CONFIRMED', 'PAYMENT_REVERSED', 'SETTLEMENT', 'SWEEP',
                   'WITHDRAWAL_LOCK', 'WITHDRAWAL_EXECUTE', 'WITHDRAWAL_RELEASE',
                   'FEE', 'ADJUSTMENT')),
    reference_type text NOT NULL,                        -- 'payment' | 'withdrawal' | 'sweep' | ...
    reference_id   uuid NOT NULL,
    description    text NOT NULL,
    created_by     text NOT NULL DEFAULT 'system',
    created_at     timestamptz NOT NULL DEFAULT now()
);
-- exactly-once posting per business event
CREATE UNIQUE INDEX ledger_journal_idempotent
    ON ledger_journal (journal_type, reference_type, reference_id);

CREATE TABLE ledger_entries (
    id          uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    journal_id  uuid NOT NULL REFERENCES ledger_journal(id),
    account_id  uuid NOT NULL REFERENCES ledger_accounts(id),
    asset_code  text NOT NULL REFERENCES assets(code),
    direction   text NOT NULL CHECK (direction IN ('DEBIT', 'CREDIT')),
    amount      numeric(38,0) NOT NULL CHECK (amount > 0),
    created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ledger_entries_account_idx ON ledger_entries (account_id, created_at);
CREATE INDEX ledger_entries_journal_idx ON ledger_entries (journal_id);

-- ── Immutability ─────────────────────────────────────────────────────────────
CREATE TRIGGER ledger_journal_append_only
    BEFORE UPDATE OR DELETE ON ledger_journal
    FOR EACH ROW EXECUTE FUNCTION forbid_mutation();
CREATE TRIGGER ledger_entries_append_only
    BEFORE UPDATE OR DELETE ON ledger_entries
    FOR EACH ROW EXECUTE FUNCTION forbid_mutation();
CREATE TRIGGER ledger_journal_no_truncate
    BEFORE TRUNCATE ON ledger_journal
    FOR EACH STATEMENT EXECUTE FUNCTION forbid_mutation();
CREATE TRIGGER ledger_entries_no_truncate
    BEFORE TRUNCATE ON ledger_entries
    FOR EACH STATEMENT EXECUTE FUNCTION forbid_mutation();

-- ── Balanced-journal enforcement (deferred to commit) ────────────────────────
CREATE OR REPLACE FUNCTION assert_journal_balanced() RETURNS trigger AS $$
DECLARE
    unbalanced record;
BEGIN
    SELECT e.journal_id, e.asset_code,
           SUM(CASE e.direction WHEN 'DEBIT' THEN e.amount ELSE -e.amount END) AS net
    INTO unbalanced
    FROM ledger_entries e
    WHERE e.journal_id = NEW.journal_id
    GROUP BY e.journal_id, e.asset_code
    HAVING SUM(CASE e.direction WHEN 'DEBIT' THEN e.amount ELSE -e.amount END) <> 0
    LIMIT 1;
    IF FOUND THEN
        RAISE EXCEPTION 'journal % is unbalanced for asset % (net %)',
            unbalanced.journal_id, unbalanced.asset_code, unbalanced.net;
    END IF;
    RETURN NULL;
END
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER ledger_entries_balanced
    AFTER INSERT ON ledger_entries
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION assert_journal_balanced();

-- ── Materialized balances (cache; proven by reconciliation worker) ──────────
CREATE TABLE account_balances (
    account_id  uuid PRIMARY KEY REFERENCES ledger_accounts(id),
    balance     numeric(38,0) NOT NULL DEFAULT 0,        -- CREDIT - DEBIT
    updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE OR REPLACE FUNCTION apply_entry_to_balance() RETURNS trigger AS $$
BEGIN
    INSERT INTO account_balances (account_id, balance, updated_at)
    VALUES (NEW.account_id,
            CASE NEW.direction WHEN 'CREDIT' THEN NEW.amount ELSE -NEW.amount END,
            now())
    ON CONFLICT (account_id) DO UPDATE
        SET balance = account_balances.balance
                      + CASE NEW.direction WHEN 'CREDIT' THEN NEW.amount ELSE -NEW.amount END,
            updated_at = now();
    RETURN NULL;
END
$$ LANGUAGE plpgsql;

CREATE TRIGGER ledger_entries_apply_balance
    AFTER INSERT ON ledger_entries
    FOR EACH ROW EXECUTE FUNCTION apply_entry_to_balance();

-- Reconciliation source of truth
CREATE VIEW ledger_balances_computed AS
SELECT a.id AS account_id, a.merchant_id, a.asset_code, a.type,
       COALESCE(SUM(CASE e.direction WHEN 'CREDIT' THEN e.amount ELSE -e.amount END), 0) AS balance
FROM ledger_accounts a
LEFT JOIN ledger_entries e ON e.account_id = a.id
GROUP BY a.id, a.merchant_id, a.asset_code, a.type;
