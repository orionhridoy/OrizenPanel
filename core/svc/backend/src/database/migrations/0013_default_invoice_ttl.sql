-- Merchant-settable default invoice expiry. When set, new invoices created without
-- an explicit expiresInMinutes use this value instead of the per-asset default TTL.
-- NULL = fall back to the asset's invoice_ttl_seconds (the built-in 1-hour default).
ALTER TABLE merchants ADD COLUMN default_invoice_ttl_seconds integer;
