-- Telegram-based second factor (alongside the existing TOTP columns on merchants).
-- The bot token is stored encrypted (AES-256-GCM under APP_ENCRYPTION_KEY), like the TOTP seed.

ALTER TABLE merchants
    ADD COLUMN telegram_enabled             boolean NOT NULL DEFAULT false,
    ADD COLUMN telegram_bot_token_encrypted text,
    ADD COLUMN telegram_chat_id             text;
