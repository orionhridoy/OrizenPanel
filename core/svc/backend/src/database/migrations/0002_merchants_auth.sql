-- Merchants, sessions, API credentials

CREATE TABLE merchants (
    id                        uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    email                     citext NOT NULL UNIQUE,
    password_hash             text NOT NULL,             -- argon2id
    name                      text NOT NULL,
    role                      text NOT NULL DEFAULT 'MERCHANT'
                              CHECK (role IN ('MERCHANT', 'ADMIN')),
    status                    text NOT NULL DEFAULT 'ACTIVE'
                              CHECK (status IN ('ACTIVE', 'SUSPENDED')),
    totp_secret_encrypted     text,                      -- AES-256-GCM under APP_ENCRYPTION_KEY
    totp_enabled              boolean NOT NULL DEFAULT false,
    force_password_change     boolean NOT NULL DEFAULT false,
    settlement_mode           text NOT NULL DEFAULT 'HOLD'
                              CHECK (settlement_mode IN ('HOLD', 'AUTO_SETTLE', 'MANUAL', 'SCHEDULED')),
    settlement_schedule_cron  text,
    underpayment_tolerance_bps integer NOT NULL DEFAULT 100
                              CHECK (underpayment_tolerance_bps BETWEEN 0 AND 2000),
    created_at                timestamptz NOT NULL DEFAULT now(),
    updated_at                timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT scheduled_needs_cron CHECK (
        settlement_mode <> 'SCHEDULED' OR settlement_schedule_cron IS NOT NULL
    )
);
CREATE TRIGGER merchants_updated_at BEFORE UPDATE ON merchants
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE refresh_tokens (
    id            uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    merchant_id   uuid NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
    token_hash    text NOT NULL UNIQUE,                  -- sha256(token)
    expires_at    timestamptz NOT NULL,
    revoked_at    timestamptz,
    replaced_by   uuid REFERENCES refresh_tokens(id),
    ip            inet,
    user_agent    text,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX refresh_tokens_merchant_idx ON refresh_tokens (merchant_id, expires_at);

CREATE TABLE api_keys (
    id               uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    merchant_id      uuid NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
    label            text NOT NULL,
    key_prefix       text NOT NULL UNIQUE,               -- 'orz_live_xxxxxxxx' lookup handle
    key_hash         text NOT NULL,                      -- sha256(full api key)
    secret_encrypted text NOT NULL,                      -- AES-256-GCM(api secret) for HMAC verification
    permissions      text[] NOT NULL DEFAULT '{invoices:read,invoices:write,balances:read,withdrawals:write,webhooks:manage}',
    last_used_at     timestamptz,
    revoked_at       timestamptz,
    created_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX api_keys_merchant_idx ON api_keys (merchant_id);
