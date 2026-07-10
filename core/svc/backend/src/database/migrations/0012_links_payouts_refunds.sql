-- Payment links (no-code shareable/reusable checkout), mass payouts, refunds.

CREATE TABLE payment_links (
    id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    merchant_id     uuid NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
    slug            text NOT NULL UNIQUE,            -- short public code (/pay/<slug>)
    title           text NOT NULL,
    description     text,
    -- pricing: fixed fiat, fixed crypto, or payer-entered amount
    fiat_currency   text,                            -- USD/EUR/GBP when fiat-priced
    fiat_amount     numeric(20,2),                   -- fixed fiat price (null = payer enters)
    asset_code      text REFERENCES assets(code),    -- fixed asset (null = payer chooses)
    crypto_amount   text,                            -- fixed crypto amount (decimal string)
    allow_custom_amount boolean NOT NULL DEFAULT false,
    redirect_url    text,
    is_active       boolean NOT NULL DEFAULT true,
    times_used      integer NOT NULL DEFAULT 0,
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX payment_links_merchant_idx ON payment_links (merchant_id, created_at DESC);

-- link invoices back to the link that created them (analytics/conversion)
ALTER TABLE invoices ADD COLUMN payment_link_id uuid REFERENCES payment_links(id);

-- ── mass payouts: a batch groups many withdrawals ─────────────────────────────
CREATE TABLE payout_batches (
    id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    merchant_id     uuid NOT NULL REFERENCES merchants(id),
    label           text,
    idempotency_key text,
    total_items     integer NOT NULL DEFAULT 0,
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX payout_batches_idem
    ON payout_batches (merchant_id, idempotency_key) WHERE idempotency_key IS NOT NULL;

ALTER TABLE withdrawals ADD COLUMN batch_id uuid REFERENCES payout_batches(id);
CREATE INDEX withdrawals_batch_idx ON withdrawals (batch_id) WHERE batch_id IS NOT NULL;

-- ── refunds: a withdrawal that returns a paid invoice to the payer ───────────
ALTER TABLE withdrawals ADD COLUMN refund_invoice_id uuid REFERENCES invoices(id);
CREATE INDEX withdrawals_refund_idx ON withdrawals (refund_invoice_id)
    WHERE refund_invoice_id IS NOT NULL;
