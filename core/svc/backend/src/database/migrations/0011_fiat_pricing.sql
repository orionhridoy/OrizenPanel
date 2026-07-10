-- Fiat-priced invoices/top-ups: the merchant enters an amount in fiat and the
-- gateway converts to crypto at the current market rate (recorded on the invoice).
ALTER TABLE invoices ADD COLUMN fiat_currency text;
ALTER TABLE invoices ADD COLUMN fiat_amount   numeric(20,2);
ALTER TABLE invoices ADD COLUMN exchange_rate numeric(38,8);   -- crypto price in fiat at creation

INSERT INTO settings (key, value) VALUES
    ('rates.fiat_currencies', '["USD","EUR","GBP"]')
ON CONFLICT (key) DO NOTHING;
