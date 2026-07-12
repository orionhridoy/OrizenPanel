-- Payment recovery hardening:
--   missing_since  - a tx must stay missing for a grace window before a payment
--                    is rejected/replaced/orphaned (transient RPC gaps must not
--                    kill a payment or reverse a credit)
--   credit_epoch   - increments on every credit reversal so a resurrected
--                    payment can be re-credited under a fresh journal reference

ALTER TABLE payments ADD COLUMN missing_since timestamptz;
ALTER TABLE payments ADD COLUMN credit_epoch integer NOT NULL DEFAULT 0;

-- resurrectable payments swept by the confirm cycle
CREATE INDEX payments_recovery_idx ON payments (asset_code, detected_at)
    WHERE status IN ('REPLACED', 'REJECTED', 'ORPHANED');

-- late-payment detection window (hours) for explorer-polled chains
INSERT INTO settings (key, value) VALUES ('payments.late_grace_hours', '24')
    ON CONFLICT (key) DO NOTHING;
