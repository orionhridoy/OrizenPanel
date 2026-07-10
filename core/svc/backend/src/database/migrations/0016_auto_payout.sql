-- "Super-automatic" withdrawals: optional auto-payout of a merchant's balance to
-- their own external wallet once it crosses a per-asset threshold, plus a retry
-- counter so a withdrawal can wait for an on-demand sweep instead of failing when
-- the treasury hasn't gathered the deposit funds yet.

ALTER TABLE merchants
    ADD COLUMN auto_payout_enabled boolean NOT NULL DEFAULT false,
    -- { "BTC": { "address": "bc1...", "minBaseUnits": "100000" }, ... }
    ADD COLUMN auto_payout_targets jsonb NOT NULL DEFAULT '{}'::jsonb;

-- how many times processApproved has deferred this withdrawal waiting for funds
ALTER TABLE withdrawals
    ADD COLUMN attempts integer NOT NULL DEFAULT 0;
