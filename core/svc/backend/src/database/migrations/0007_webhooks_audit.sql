-- Webhook endpoints/deliveries and append-only audit trail

CREATE TABLE webhook_endpoints (
    id               uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    merchant_id      uuid NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
    url              text NOT NULL,
    secret_encrypted text NOT NULL,        -- AES-256-GCM; used for X-Orizen-Signature HMAC
    events           text[] NOT NULL DEFAULT
                     '{invoice.seen,invoice.confirming,invoice.paid,invoice.underpaid,invoice.overpaid,invoice.expired,invoice.invalid,withdrawal.broadcast,withdrawal.confirmed,withdrawal.failed}',
    is_active        boolean NOT NULL DEFAULT true,
    created_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX webhook_endpoints_merchant_idx ON webhook_endpoints (merchant_id);

CREATE TABLE webhook_deliveries (
    id                 uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    endpoint_id        uuid NOT NULL REFERENCES webhook_endpoints(id) ON DELETE CASCADE,
    event_type         text NOT NULL,
    payload            jsonb NOT NULL,
    status             text NOT NULL DEFAULT 'PENDING' CHECK (status IN
                       ('PENDING', 'DELIVERED', 'FAILED', 'DEAD')),
    attempt_count      integer NOT NULL DEFAULT 0,
    next_attempt_at    timestamptz NOT NULL DEFAULT now(),
    last_response_code integer,
    last_error         text,
    created_at         timestamptz NOT NULL DEFAULT now(),
    delivered_at       timestamptz
);
CREATE INDEX webhook_deliveries_due_idx ON webhook_deliveries (next_attempt_at)
    WHERE status IN ('PENDING', 'FAILED');
CREATE INDEX webhook_deliveries_endpoint_idx ON webhook_deliveries (endpoint_id, created_at DESC);

CREATE TABLE audit_logs (
    id            uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    actor_type    text NOT NULL CHECK (actor_type IN ('MERCHANT', 'ADMIN', 'API_KEY', 'SYSTEM')),
    actor_id      uuid,
    action        text NOT NULL,           -- e.g. 'auth.login', 'withdrawal.approve'
    resource_type text,
    resource_id   text,
    ip            inet,
    user_agent    text,
    metadata      jsonb NOT NULL DEFAULT '{}',
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX audit_logs_time_idx ON audit_logs (created_at DESC);
CREATE INDEX audit_logs_actor_idx ON audit_logs (actor_type, actor_id, created_at DESC);

CREATE TRIGGER audit_logs_append_only
    BEFORE UPDATE OR DELETE ON audit_logs
    FOR EACH ROW EXECUTE FUNCTION forbid_mutation();
CREATE TRIGGER audit_logs_no_truncate
    BEFORE TRUNCATE ON audit_logs
    FOR EACH STATEMENT EXECUTE FUNCTION forbid_mutation();
