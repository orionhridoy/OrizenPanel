import { Inject, Injectable, NotFoundException } from '@nestjs/common';
import { APP_CONFIG, AppConfig } from '../../config/config';
import { DatabaseService } from '../../database/database.service';
import { AuditService } from '../audit/audit.service';
import { encryptSecret, randomHex, sha256Hex } from '../../common/utils/crypto.util';

export const API_KEY_PERMISSIONS = [
  'invoices:read',
  'invoices:write',
  'balances:read',
  'withdrawals:write',
  'webhooks:manage',
  'store:manage',
] as const;
export type ApiKeyPermission = (typeof API_KEY_PERMISSIONS)[number];

export interface ApiKeyPublicRow {
  id: string;
  label: string;
  key_prefix: string;
  permissions: string[];
  last_used_at: string | null;
  revoked_at: string | null;
  created_at: string;
}

@Injectable()
export class ApiKeysService {
  constructor(
    private readonly db: DatabaseService,
    private readonly audit: AuditService,
    @Inject(APP_CONFIG) private readonly config: AppConfig,
  ) {}

  /**
   * Creates an API key. The full key and secret are returned EXACTLY ONCE;
   * only sha256(key) and AES-GCM(secret) are persisted.
   */
  async create(
    merchantId: string,
    label: string,
    permissions: ApiKeyPermission[],
    ip?: string,
  ): Promise<{ apiKey: string; apiSecret: string; row: ApiKeyPublicRow }> {
    const apiKey = `orz_live_${randomHex(24)}`;
    const apiSecret = randomHex(32);
    const keyPrefix = apiKey.slice(0, 17);
    const row = await this.db.queryOne<ApiKeyPublicRow>(
      `INSERT INTO api_keys (merchant_id, label, key_prefix, key_hash, secret_encrypted, permissions)
       VALUES ($1, $2, $3, $4, $5, $6)
       RETURNING id, label, key_prefix, permissions, last_used_at, revoked_at, created_at`,
      [
        merchantId,
        label,
        keyPrefix,
        sha256Hex(apiKey),
        encryptSecret(apiSecret, this.config.appEncryptionKey),
        permissions,
      ],
    );
    await this.audit.log({
      actorType: 'MERCHANT',
      actorId: merchantId,
      action: 'api_key.created',
      resourceType: 'api_key',
      resourceId: row?.id,
      ip,
      metadata: { label, permissions },
    });
    return { apiKey, apiSecret, row: row as ApiKeyPublicRow };
  }

  async list(merchantId: string): Promise<ApiKeyPublicRow[]> {
    return this.db.query<ApiKeyPublicRow>(
      `SELECT id, label, key_prefix, permissions, last_used_at, revoked_at, created_at
         FROM api_keys WHERE merchant_id = $1 ORDER BY created_at DESC`,
      [merchantId],
    );
  }

  async revoke(merchantId: string, keyId: string, ip?: string): Promise<void> {
    const result = await this.db.query<{ id: string }>(
      `UPDATE api_keys SET revoked_at = now()
        WHERE id = $1 AND merchant_id = $2 AND revoked_at IS NULL
        RETURNING id`,
      [keyId, merchantId],
    );
    if (result.length === 0) throw new NotFoundException('API key not found or already revoked');
    await this.audit.log({
      actorType: 'MERCHANT',
      actorId: merchantId,
      action: 'api_key.revoked',
      resourceType: 'api_key',
      resourceId: keyId,
      ip,
    });
  }
}
