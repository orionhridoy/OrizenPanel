import {
  BadRequestException,
  ConflictException,
  ForbiddenException,
  Inject,
  Injectable,
  Logger,
  NotFoundException,
  UnauthorizedException,
} from '@nestjs/common';
import { createHmac, randomInt, timingSafeEqual } from 'crypto';
import * as jwt from 'jsonwebtoken';
import { authenticator } from 'otplib';
import IORedis from 'ioredis';
import { PoolClient } from 'pg';
import { APP_CONFIG, AppConfig } from '../../config/config';
import { REDIS_CONNECTION } from '../../redis/queue.module';
import { DatabaseService } from '../../database/database.service';
import { AuditService } from '../audit/audit.service';
import {
  decryptSecret,
  encryptSecret,
  hashPassword,
  randomHex,
  sha256Hex,
  verifyPassword,
} from '../../common/utils/crypto.util';
import { captchaSvg, newCaptchaCode } from '../../common/utils/captcha.util';
import { looksLikeBotToken, sendTelegramMessage } from '../../common/utils/telegram.util';

const ACCESS_TTL_SECONDS = 15 * 60;
const REFRESH_TTL_DAYS = 30;

interface MerchantAuthRow {
  id: string;
  email: string;
  password_hash: string;
  role: 'MERCHANT' | 'ADMIN';
  status: string;
  totp_enabled: boolean;
  totp_secret_encrypted: string | null;
  telegram_enabled: boolean;
  force_password_change: boolean;
}

export interface TokenPair {
  accessToken: string;
  refreshToken: string;
  expiresIn: number;
  merchant: { id: string; email: string; name?: string; role: string; forcePasswordChange: boolean };
}

/** Returned by login() when a second factor is still required (TOTP app and/or Telegram). */
export interface TwofaChallenge {
  twofaRequired: true;
  ticket: string;
  methods: { totp: boolean; telegram: boolean };
}

interface TwofaSecrets {
  id: string;
  totp_enabled: boolean;
  totp_secret_encrypted: string | null;
  telegram_enabled: boolean;
}

const CAPTCHA_TTL_SECONDS = 300;
const TWOFA_TTL_SECONDS = 300;

@Injectable()
export class AuthService {
  private readonly logger = new Logger(AuthService.name);

  constructor(
    private readonly db: DatabaseService,
    private readonly audit: AuditService,
    @Inject(APP_CONFIG) private readonly config: AppConfig,
    @Inject(REDIS_CONNECTION) private readonly redis: IORedis,
  ) {}

  // ---- Login captcha (self-contained SVG, challenge stored in Redis) ----
  async createCaptcha(): Promise<{ id: string; svg: string }> {
    const code = newCaptchaCode();
    const id = randomHex(16);
    await this.redis.set(`captcha:${id}`, code.toUpperCase(), 'EX', CAPTCHA_TTL_SECONDS);
    return { id, svg: captchaSvg(code) };
  }

  private async consumeCaptcha(id: string, answer: string): Promise<boolean> {
    if (!id || !answer) return false;
    const key = `captcha:${id}`;
    const want = await this.redis.get(key);
    await this.redis.del(key); // single-use
    return !!want && want === answer.trim().toUpperCase();
  }

  /** Whether new merchant self-registration is allowed (admin-toggled; default OFF
   *  for safety - the operator explicitly turns sign-ups on from the Admin page). */
  async isRegistrationEnabled(): Promise<boolean> {
    const row = await this.db.queryOne<{ value: boolean }>(
      `SELECT value FROM settings WHERE key = 'auth.registration_enabled'`,
    );
    return row?.value ?? false;
  }

  async setRegistrationEnabled(enabled: boolean): Promise<void> {
    await this.db.query(
      `INSERT INTO settings (key, value) VALUES ('auth.registration_enabled', $1::jsonb)
       ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()`,
      [JSON.stringify(enabled)],
    );
  }

  async register(input: { email: string; password: string; name: string; ip?: string }): Promise<TokenPair> {
    if (!(await this.isRegistrationEnabled())) {
      throw new ForbiddenException('New account registration is currently disabled.');
    }
    const passwordHash = await hashPassword(input.password);
    let merchantId: string;
    try {
      const row = await this.db.queryOne<{ id: string }>(
        `INSERT INTO merchants (email, password_hash, name) VALUES ($1, $2, $3) RETURNING id`,
        [input.email, passwordHash, input.name],
      );
      merchantId = (row as { id: string }).id;
    } catch (err) {
      if ((err as { code?: string }).code === '23505') {
        throw new ConflictException('email already registered');
      }
      throw err;
    }
    await this.audit.log({
      actorType: 'MERCHANT',
      actorId: merchantId,
      action: 'auth.registered',
      ip: input.ip,
    });
    return this.issueTokens(merchantId, input.ip);
  }

  /** Verify an HMAC signed by the Orizen control panel (shared PANEL_SSO_SECRET). */
  private assertPanel(email: string, ts: string, sig: string): void {
    const secret = this.config.panelSsoSecret;
    if (!secret) throw new NotFoundException();   // feature disabled when no secret is set
    const age = Math.abs(Date.now() - Number(ts));
    if (!ts || Number.isNaN(age) || age > 120_000) throw new UnauthorizedException('stale request');
    const expected = createHmac('sha256', secret).update(`${email}.${ts}`).digest('hex');
    const a = Buffer.from(expected);
    const b = Buffer.from(String(sig || ''));
    if (a.length !== b.length || !timingSafeEqual(a, b)) throw new UnauthorizedException('bad panel signature');
  }

  /** Panel-driven SSO: the control panel proves itself with the shared secret and gets a session
   *  for the gateway's admin - no password needed (the panel never stores the password). */
  async panelLogin(email: string, ts: string, sig: string, ip?: string): Promise<TokenPair> {
    this.assertPanel(email, ts, sig);
    const merchant = await this.db.queryOne<{ id: string; status: string }>(
      `SELECT id, status FROM merchants WHERE email = $1`,
      [email],
    );
    if (!merchant) throw new UnauthorizedException('unknown merchant');
    if (merchant.status !== 'ACTIVE') throw new UnauthorizedException('account suspended');
    await this.audit.log({ actorType: 'ADMIN', actorId: merchant.id, action: 'auth.panel_sso', ip });
    return this.issueTokens(merchant.id, ip);
  }

  /** Panel-driven admin password reset (the panel generates a fresh password and shows it once). */
  async panelReset(email: string, ts: string, sig: string, newPassword: string, ip?: string): Promise<void> {
    this.assertPanel(email, ts, sig);
    if (!newPassword || newPassword.length < 12) throw new BadRequestException('password too short');
    const hash = await hashPassword(newPassword);
    await this.db.query(
      `UPDATE merchants SET password_hash = $2, force_password_change = false WHERE email = $1`,
      [email, hash],
    );
    await this.audit.log({ actorType: 'ADMIN', action: 'auth.panel_reset', ip, metadata: { email } });
  }

  async login(input: {
    email: string;
    password: string;
    captchaId: string;
    captcha: string;
    ip?: string;
    userAgent?: string;
  }): Promise<TokenPair | TwofaChallenge> {
    if (!(await this.consumeCaptcha(input.captchaId, input.captcha))) {
      await this.audit.log({
        actorType: 'SYSTEM',
        action: 'auth.captcha_failed',
        ip: input.ip,
        metadata: { email: input.email },
      });
      throw new UnauthorizedException('captcha incorrect');
    }
    const merchant = await this.db.queryOne<MerchantAuthRow>(
      `SELECT id, email, password_hash, role, status, totp_enabled,
              totp_secret_encrypted, telegram_enabled, force_password_change
         FROM merchants WHERE email = $1`,
      [input.email],
    );
    // constant-shape flow: verify against a dummy hash when the user is unknown
    const passwordOk = merchant
      ? await verifyPassword(input.password, merchant.password_hash)
      : (await verifyPassword(input.password, await hashPassword(randomHex(8))), false);
    if (!merchant || !passwordOk) {
      await this.audit.log({
        actorType: 'SYSTEM',
        action: 'auth.login_failed',
        ip: input.ip,
        metadata: { email: input.email },
      });
      throw new UnauthorizedException('invalid credentials');
    }
    if (merchant.status !== 'ACTIVE') {
      throw new UnauthorizedException('account suspended');
    }
    // Second factor still required? Hand back a short-lived ticket instead of tokens.
    if (merchant.totp_enabled || merchant.telegram_enabled) {
      const ticket = jwt.sign(
        { sub: merchant.id, type: '2fa' },
        this.config.jwtAccessSecret,
        { algorithm: 'HS256', expiresIn: TWOFA_TTL_SECONDS },
      );
      // Auto-send the Telegram code only when it's the sole method; if the app is also
      // enabled, let the user choose (they call /auth/login/2fa/send to receive a code).
      if (merchant.telegram_enabled && !merchant.totp_enabled) await this.sendTelegramCode(merchant.id);
      await this.audit.log({
        actorType: merchant.role === 'ADMIN' ? 'ADMIN' : 'MERCHANT',
        actorId: merchant.id,
        action: 'auth.twofa_challenge',
        ip: input.ip,
      });
      return {
        twofaRequired: true,
        ticket,
        methods: { totp: merchant.totp_enabled, telegram: merchant.telegram_enabled },
      };
    }
    await this.audit.log({
      actorType: merchant.role === 'ADMIN' ? 'ADMIN' : 'MERCHANT',
      actorId: merchant.id,
      action: 'auth.login',
      ip: input.ip,
      userAgent: input.userAgent,
    });
    return this.issueTokens(merchant.id, input.ip, input.userAgent);
  }

  /** Stage 2 of login: verify the second factor against the ticket from login(). */
  async loginTwofa(ticket: string, code: string, ip?: string, userAgent?: string): Promise<TokenPair> {
    let sub: string;
    try {
      const payload = jwt.verify(ticket, this.config.jwtAccessSecret) as jwt.JwtPayload;
      if (payload.type !== '2fa' || !payload.sub) throw new Error('wrong ticket');
      sub = String(payload.sub);
    } catch {
      throw new UnauthorizedException('two-step session expired - sign in again');
    }
    const merchant = await this.db.queryOne<TwofaSecrets & { role: string; status: string }>(
      `SELECT id, role, status, totp_enabled, totp_secret_encrypted, telegram_enabled
         FROM merchants WHERE id = $1`,
      [sub],
    );
    if (!merchant || merchant.status !== 'ACTIVE') {
      throw new UnauthorizedException('account unavailable');
    }
    if (!(await this.verifyTwofaCode(merchant, code))) {
      await this.audit.log({ actorType: 'SYSTEM', action: 'auth.twofa_failed', actorId: merchant.id, ip });
      throw new UnauthorizedException('that code is not valid');
    }
    await this.audit.log({
      actorType: merchant.role === 'ADMIN' ? 'ADMIN' : 'MERCHANT',
      actorId: merchant.id,
      action: 'auth.login',
      ip,
      userAgent,
    });
    return this.issueTokens(merchant.id, ip, userAgent);
  }

  /** Login method-choice: send a Telegram code for a pending 2FA ticket (user picked Telegram). */
  async loginTwofaSend(ticket: string): Promise<{ ok: true }> {
    let sub: string;
    try {
      const payload = jwt.verify(ticket, this.config.jwtAccessSecret) as jwt.JwtPayload;
      if (payload.type !== '2fa' || !payload.sub) throw new Error('bad ticket');
      sub = String(payload.sub);
    } catch {
      throw new UnauthorizedException('two-step session expired - sign in again');
    }
    const m = await this.db.queryOne<{ telegram_enabled: boolean }>(
      `SELECT telegram_enabled FROM merchants WHERE id = $1`,
      [sub],
    );
    if (!m?.telegram_enabled) throw new BadRequestException('Telegram 2FA is not enabled');
    await this.sendTelegramCode(sub);
    return { ok: true };
  }

  /** Accept a 6-digit code from the TOTP app OR the one Telegram just delivered. */
  private async verifyTwofaCode(merchant: TwofaSecrets, code: string): Promise<boolean> {
    const c = (code || '').replace(/\D/g, '');
    if (c.length !== 6) return false;
    if (merchant.totp_enabled && merchant.totp_secret_encrypted) {
      const secret = decryptSecret(merchant.totp_secret_encrypted, this.config.appEncryptionKey);
      if (authenticator.verify({ token: c, secret })) return true;
    }
    if (merchant.telegram_enabled) {
      const key = `2fa:${merchant.id}`;
      const want = await this.redis.get(key);
      if (want) {
        await this.redis.del(key); // single-use
        if (timingSafeEqual(Buffer.from(want), Buffer.from(c))) return true;
      }
    }
    return false;
  }

  private async sendTelegramCode(merchantId: string): Promise<void> {
    const row = await this.db.queryOne<{ telegram_bot_token_encrypted: string | null; telegram_chat_id: string | null }>(
      `SELECT telegram_bot_token_encrypted, telegram_chat_id FROM merchants WHERE id = $1`,
      [merchantId],
    );
    if (!row?.telegram_bot_token_encrypted || !row.telegram_chat_id) return;
    const code = String(randomInt(0, 1_000_000)).padStart(6, '0');
    await this.redis.set(`2fa:${merchantId}`, code, 'EX', TWOFA_TTL_SECONDS);
    const token = decryptSecret(row.telegram_bot_token_encrypted, this.config.appEncryptionKey);
    await sendTelegramMessage(token, row.telegram_chat_id, `Orizen Pay login code: ${code}\nValid for 5 minutes. If this wasn't you, change your password.`);
  }

  async refresh(refreshToken: string, ip?: string): Promise<TokenPair> {
    const tokenHash = sha256Hex(refreshToken);
    return this.db.tx(async (client) => {
      const row = (
        await client.query<{ id: string; merchant_id: string; expires_at: string; revoked_at: string | null }>(
          `SELECT id, merchant_id, expires_at, revoked_at FROM refresh_tokens
            WHERE token_hash = $1 FOR UPDATE`,
          [tokenHash],
        )
      ).rows[0];
      if (!row || row.revoked_at !== null || new Date(row.expires_at).getTime() < Date.now()) {
        // reuse of a revoked token -> assume theft, revoke the whole family
        if (row?.revoked_at) {
          await client.query(
            `UPDATE refresh_tokens SET revoked_at = now()
              WHERE merchant_id = $1 AND revoked_at IS NULL`,
            [row.merchant_id],
          );
          this.logger.warn(`refresh token reuse detected for merchant ${row.merchant_id}`);
        }
        throw new UnauthorizedException('invalid refresh token');
      }
      const pair = await this.issueTokensTx(client, row.merchant_id, ip);
      await client.query(
        `UPDATE refresh_tokens SET revoked_at = now(), replaced_by = $2 WHERE id = $1`,
        [row.id, pair.newTokenId],
      );
      return pair.tokens;
    });
  }

  async logout(merchantId: string, refreshToken: string): Promise<void> {
    await this.db.query(
      `UPDATE refresh_tokens SET revoked_at = now()
        WHERE merchant_id = $1 AND token_hash = $2 AND revoked_at IS NULL`,
      [merchantId, sha256Hex(refreshToken)],
    );
  }

  async changePassword(merchantId: string, currentPassword: string, newPassword: string, ip?: string): Promise<void> {
    const merchant = await this.db.queryOne<{ password_hash: string }>(
      `SELECT password_hash FROM merchants WHERE id = $1`,
      [merchantId],
    );
    if (!merchant || !(await verifyPassword(currentPassword, merchant.password_hash))) {
      throw new UnauthorizedException('current password incorrect');
    }
    const newHash = await hashPassword(newPassword);
    await this.db.query(
      `UPDATE merchants SET password_hash = $2, force_password_change = false WHERE id = $1`,
      [merchantId, newHash],
    );
    // all sessions die with the old password
    await this.db.query(
      `UPDATE refresh_tokens SET revoked_at = now() WHERE merchant_id = $1 AND revoked_at IS NULL`,
      [merchantId],
    );
    await this.audit.log({
      actorType: 'MERCHANT',
      actorId: merchantId,
      action: 'auth.password_changed',
      ip,
    });
  }

  async totpSetup(merchantId: string): Promise<{ secret: string; otpauthUrl: string }> {
    const merchant = await this.db.queryOne<{ email: string; totp_enabled: boolean }>(
      `SELECT email, totp_enabled FROM merchants WHERE id = $1`,
      [merchantId],
    );
    if (!merchant) throw new UnauthorizedException();
    if (merchant.totp_enabled) throw new ConflictException('TOTP already enabled');
    const secret = authenticator.generateSecret(20);
    await this.db.query(`UPDATE merchants SET totp_secret_encrypted = $2 WHERE id = $1`, [
      merchantId,
      encryptSecret(secret, this.config.appEncryptionKey),
    ]);
    return {
      secret,
      otpauthUrl: authenticator.keyuri(merchant.email, 'Orizen Pay', secret),
    };
  }

  async totpEnable(merchantId: string, code: string, ip?: string): Promise<void> {
    const merchant = await this.db.queryOne<{ totp_secret_encrypted: string | null }>(
      `SELECT totp_secret_encrypted FROM merchants WHERE id = $1`,
      [merchantId],
    );
    if (!merchant?.totp_secret_encrypted) throw new ConflictException('run TOTP setup first');
    const secret = decryptSecret(merchant.totp_secret_encrypted, this.config.appEncryptionKey);
    if (!authenticator.verify({ token: code, secret })) {
      throw new UnauthorizedException('invalid TOTP code');
    }
    await this.db.query(`UPDATE merchants SET totp_enabled = true WHERE id = $1`, [merchantId]);
    await this.audit.log({
      actorType: 'MERCHANT',
      actorId: merchantId,
      action: 'auth.totp_enabled',
      ip,
    });
  }

  /** Turning off the authenticator requires a valid current app code (verified by the method). */
  async totpDisable(merchantId: string, code: string, ip?: string): Promise<void> {
    const row = await this.db.queryOne<{ totp_enabled: boolean; totp_secret_encrypted: string | null }>(
      `SELECT totp_enabled, totp_secret_encrypted FROM merchants WHERE id = $1`,
      [merchantId],
    );
    if (!row?.totp_enabled) return;
    const secret = row.totp_secret_encrypted ? decryptSecret(row.totp_secret_encrypted, this.config.appEncryptionKey) : '';
    if (!secret || !authenticator.verify({ token: (code || '').replace(/\D/g, ''), secret })) {
      throw new UnauthorizedException('Enter a valid authenticator code to turn it off.');
    }
    await this.db.query(
      `UPDATE merchants SET totp_enabled = false, totp_secret_encrypted = NULL WHERE id = $1`,
      [merchantId],
    );
    await this.audit.log({ actorType: 'MERCHANT', actorId: merchantId, action: 'auth.totp_disabled', ip });
  }

  /** Current second-factor + withdrawal-2FA state, for the Settings screen. */
  async twofaStatus(
    merchantId: string,
  ): Promise<{ totp: boolean; telegram: boolean; telegramChatId: string | null; withdrawal2fa: boolean }> {
    const row = await this.db.queryOne<{ totp_enabled: boolean; telegram_enabled: boolean; telegram_chat_id: string | null; withdrawal_2fa_enabled: boolean }>(
      `SELECT totp_enabled, telegram_enabled, telegram_chat_id, withdrawal_2fa_enabled FROM merchants WHERE id = $1`,
      [merchantId],
    );
    return {
      totp: !!row?.totp_enabled,
      telegram: !!row?.telegram_enabled,
      telegramChatId: row?.telegram_chat_id ?? null,
      withdrawal2fa: !!row?.withdrawal_2fa_enabled,
    };
  }

  /** Toggle "require 2FA to withdraw". Can only be turned on when a 2FA method is set up. */
  async setWithdrawal2fa(merchantId: string, enabled: boolean, ip?: string): Promise<void> {
    if (enabled) {
      const m = await this.db.queryOne<{ totp_enabled: boolean; telegram_enabled: boolean }>(
        `SELECT totp_enabled, telegram_enabled FROM merchants WHERE id = $1`,
        [merchantId],
      );
      if (!m?.totp_enabled && !m?.telegram_enabled) {
        throw new BadRequestException('Set up an authenticator app or Telegram 2FA first.');
      }
    }
    await this.db.query(`UPDATE merchants SET withdrawal_2fa_enabled = $2 WHERE id = $1`, [merchantId, enabled]);
    await this.audit.log({ actorType: 'MERCHANT', actorId: merchantId, action: enabled ? 'auth.withdrawal_2fa_on' : 'auth.withdrawal_2fa_off', ip });
  }

  /** Whether withdrawals require a 2FA code, and verifying that code (used by the withdrawal flow). */
  async isWithdrawal2fa(merchantId: string): Promise<boolean> {
    const row = await this.db.queryOne<{ withdrawal_2fa_enabled: boolean }>(
      `SELECT withdrawal_2fa_enabled FROM merchants WHERE id = $1`,
      [merchantId],
    );
    return !!row?.withdrawal_2fa_enabled;
  }
  async sendWithdrawalTelegramCode(merchantId: string): Promise<void> {
    await this.sendTelegramCode(merchantId);
  }
  async verifyWithdrawalCode(merchantId: string, code: string): Promise<boolean> {
    const row = await this.db.queryOne<TwofaSecrets>(
      `SELECT id, totp_enabled, totp_secret_encrypted, telegram_enabled FROM merchants WHERE id = $1`,
      [merchantId],
    );
    if (!row) return false;
    return this.verifyTwofaCode(row, code);
  }

  /** Save the bot token + chat ID (encrypted) and send a one-time test code to confirm delivery. */
  async telegramSetup(merchantId: string, botToken: string, chatId: string): Promise<{ ok: true }> {
    if (!looksLikeBotToken(botToken)) {
      throw new BadRequestException('That does not look like a valid Telegram bot token (like 123456789:AA...).');
    }
    await this.db.query(
      `UPDATE merchants SET telegram_bot_token_encrypted = $2, telegram_chat_id = $3 WHERE id = $1`,
      [merchantId, encryptSecret(botToken, this.config.appEncryptionKey), chatId],
    );
    const code = String(randomInt(0, 1_000_000)).padStart(6, '0');
    await this.redis.set(`tgtest:${merchantId}`, code, 'EX', 600);
    const ok = await sendTelegramMessage(botToken, chatId, `Orizen Pay test code: ${code}\nEnter it to switch on Telegram 2FA.`);
    if (!ok) {
      throw new BadRequestException(
        'Saved, but Telegram would not deliver the message. Check the token and make sure you pressed Start on the bot, then try again.',
      );
    }
    return { ok: true };
  }

  async telegramEnable(merchantId: string, code: string, ip?: string): Promise<void> {
    const want = await this.redis.get(`tgtest:${merchantId}`);
    const c = (code || '').replace(/\D/g, '');
    if (!want || c.length !== 6 || want !== c) {
      throw new UnauthorizedException('That code does not match the one we sent.');
    }
    await this.redis.del(`tgtest:${merchantId}`);
    await this.db.query(`UPDATE merchants SET telegram_enabled = true WHERE id = $1`, [merchantId]);
    await this.audit.log({ actorType: 'MERCHANT', actorId: merchantId, action: 'auth.telegram_enabled', ip });
  }

  /** Step 1 of turning off Telegram 2FA: send a confirmation code to the linked chat. */
  async telegramDisableSend(merchantId: string): Promise<{ ok: true }> {
    await this.sendTelegramCode(merchantId);
    return { ok: true };
  }
  /** Step 2: turning off Telegram 2FA requires the code we just sent (verified by the method). */
  async telegramDisable(merchantId: string, code: string, ip?: string): Promise<void> {
    const key = `2fa:${merchantId}`;
    const want = await this.redis.get(key);
    const c = (code || '').replace(/\D/g, '');
    if (!want || c.length !== 6 || want !== c) {
      throw new UnauthorizedException('Enter the valid Telegram code to turn it off.');
    }
    await this.redis.del(key);
    await this.db.query(
      `UPDATE merchants SET telegram_enabled = false, telegram_bot_token_encrypted = NULL, telegram_chat_id = NULL WHERE id = $1`,
      [merchantId],
    );
    await this.audit.log({ actorType: 'MERCHANT', actorId: merchantId, action: 'auth.telegram_disabled', ip });
  }

  private async issueTokens(merchantId: string, ip?: string, userAgent?: string): Promise<TokenPair> {
    return this.db.tx(async (client) => {
      const pair = await this.issueTokensTx(client, merchantId, ip, userAgent);
      return pair.tokens;
    });
  }

  private async issueTokensTx(
    client: PoolClient,
    merchantId: string,
    ip?: string,
    userAgent?: string,
  ): Promise<{ tokens: TokenPair; newTokenId: string }> {
    const merchant = (
      await client.query(
        `SELECT id, email, name, role, force_password_change FROM merchants WHERE id = $1`,
        [merchantId],
      )
    ).rows[0] as {
      id: string;
      email: string;
      name: string;
      role: 'MERCHANT' | 'ADMIN';
      force_password_change: boolean;
    };
    const accessToken = jwt.sign(
      { sub: merchant.id, role: merchant.role, type: 'access' },
      this.config.jwtAccessSecret,
      { algorithm: 'HS256', expiresIn: ACCESS_TTL_SECONDS },
    );
    const refreshToken = randomHex(48);
    const inserted = (
      await client.query(
        `INSERT INTO refresh_tokens (merchant_id, token_hash, expires_at, ip, user_agent)
         VALUES ($1, $2, now() + interval '${REFRESH_TTL_DAYS} days', $3, $4)
         RETURNING id`,
        [merchant.id, sha256Hex(refreshToken), ip ?? null, userAgent ?? null],
      )
    ).rows[0] as { id: string };
    return {
      newTokenId: inserted.id,
      tokens: {
        accessToken,
        refreshToken,
        expiresIn: ACCESS_TTL_SECONDS,
        merchant: {
          id: merchant.id,
          email: merchant.email,
          name: merchant.name,
          role: merchant.role,
          forcePasswordChange: merchant.force_password_change,
        },
      },
    };
  }
}
