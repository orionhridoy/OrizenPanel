-- Withdrawals and treasury sweeps

CREATE TABLE withdrawals (
    id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    merchant_id         uuid NOT NULL REFERENCES merchants(id),
    asset_code          text NOT NULL REFERENCES assets(code),
    amount              numeric(38,0) NOT NULL CHECK (amount > 0),   -- amount debited from merchant
    network_fee         numeric(38,0),                               -- filled after broadcast
    destination_address text NOT NULL,
    destination_tag     bigint,                                      -- XRP
    status              text NOT NULL DEFAULT 'PENDING' CHECK (status IN
                        ('PENDING',      -- requested, funds locked
                         'APPROVED',     -- risk checks passed / admin approved
                         'SIGNING',      -- sent to signer
                         'BROADCAST',    -- on the network
                         'CONFIRMED',
                         'FAILED',       -- funds released back
                         'REJECTED',     -- risk/admin rejection, funds released
                         'CANCELLED')),  -- merchant cancelled while PENDING
    risk_flags          jsonb NOT NULL DEFAULT '[]',
    requires_admin_approval boolean NOT NULL DEFAULT false,
    approved_by         uuid REFERENCES merchants(id),
    idempotency_key     text,
    txid                text,
    error               text,
    requested_ip        inet,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    broadcast_at        timestamptz,
    confirmed_at        timestamptz
);
CREATE TRIGGER withdrawals_updated_at BEFORE UPDATE ON withdrawals
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE UNIQUE INDEX withdrawals_idempotency_unique
    ON withdrawals (merchant_id, idempotency_key) WHERE idempotency_key IS NOT NULL;
CREATE INDEX withdrawals_merchant_idx ON withdrawals (merchant_id, created_at DESC);
CREATE INDEX withdrawals_active_idx ON withdrawals (status)
    WHERE status IN ('PENDING', 'APPROVED', 'SIGNING', 'BROADCAST');

CREATE TABLE sweeps (
    id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    asset_code          text NOT NULL REFERENCES assets(code),
    from_wallet_id      uuid NOT NULL REFERENCES wallets(id),
    treasury_wallet_id  uuid NOT NULL REFERENCES wallets(id),
    status              text NOT NULL DEFAULT 'PLANNED' CHECK (status IN
                        ('PLANNED', 'SIGNING', 'BROADCAST', 'CONFIRMED', 'FAILED')),
    total_amount        numeric(38,0) NOT NULL CHECK (total_amount > 0),
    network_fee         numeric(38,0),
    txid                text,
    error               text,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    confirmed_at        timestamptz
);
CREATE TRIGGER sweeps_updated_at BEFORE UPDATE ON sweeps
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE INDEX sweeps_active_idx ON sweeps (status)
    WHERE status IN ('PLANNED', 'SIGNING', 'BROADCAST');

CREATE TABLE sweep_inputs (
    id          uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    sweep_id    uuid NOT NULL REFERENCES sweeps(id),
    payment_id  uuid NOT NULL REFERENCES payments(id),
    address_id  uuid NOT NULL REFERENCES wallet_addresses(id),
    amount      numeric(38,0) NOT NULL CHECK (amount > 0)
);
-- a confirmed deposit is swept at most once
CREATE UNIQUE INDEX sweep_inputs_payment_unique ON sweep_inputs (payment_id);
CREATE INDEX sweep_inputs_sweep_idx ON sweep_inputs (sweep_id);
