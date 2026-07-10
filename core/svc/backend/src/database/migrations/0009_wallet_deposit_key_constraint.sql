-- XRP deposit wallets hold a single funded account ADDRESS (with a unique
-- destination tag per invoice) rather than an HD xpub. The original
-- hd_needs_xpub constraint rejected such rows. Relax it so a DEPOSIT_HD /
-- WATCH_ONLY wallet may present EITHER an xpub (UTXO/EVM/Tron) OR an address (XRP).
ALTER TABLE wallets DROP CONSTRAINT IF EXISTS hd_needs_xpub;
ALTER TABLE wallets ADD CONSTRAINT hd_needs_xpub_or_address CHECK (
    type NOT IN ('DEPOSIT_HD', 'WATCH_ONLY') OR xpub IS NOT NULL OR address IS NOT NULL
);
