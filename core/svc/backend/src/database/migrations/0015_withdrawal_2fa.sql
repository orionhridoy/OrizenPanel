-- Optional "require a 2FA code to withdraw" per merchant.
ALTER TABLE merchants ADD COLUMN withdrawal_2fa_enabled boolean NOT NULL DEFAULT false;
