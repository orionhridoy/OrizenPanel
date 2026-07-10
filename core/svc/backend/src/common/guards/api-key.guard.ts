import {
  CanActivate,
  ExecutionContext,
  Inject,
  Injectable,
  SetMetadata,
  UnauthorizedException,
  ForbiddenException,
} from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import { APP_CONFIG, AppConfig } from '../../config/config';
import { DatabaseService } from '../../database/database.service';
import {
  decryptSecret,
  hmacSha256Hex,
  sha256Hex,
  timingSafeEqualStr,
} from '../utils/crypto.util';
import { AuthenticatedRequest } from '../types';

export const PERMISSION_KEY = 'orizen:permission';
export const RequirePermission = (permission: string) => SetMetadata(PERMISSION_KEY, permission);

const MAX_CLOCK_SKEW_MS = 5 * 60 * 1000;

interface ApiKeyRow {
  id: string;
  merchant_id: string;
  key_hash: string;
  secret_encrypted: string;
  permissions: string[];
  revoked_at: string | null;
  merchant_status: string;
  merchant_role: 'MERCHANT' | 'ADMIN';
}

/**
 * Merchant API authentication:
 *   X-API-KEY:    orz_live_<hex>            (prefix identifies the key row)
 *   X-TIMESTAMP:  unix milliseconds         (±5 min skew allowed)
 *   X-SIGNATURE:  HMAC-SHA256(secret, `${timestamp}.${METHOD}.${path}.${sha256(body)}`)
 */
@Injectable()
export class ApiKeyGuard implements CanActivate {
  constructor(
    private readonly db: DatabaseService,
    private readonly reflector: Reflector,
    @Inject(APP_CONFIG) private readonly config: AppConfig,
  ) {}

  async canActivate(context: ExecutionContext): Promise<boolean> {
    const request = context.switchToHttp().getRequest<AuthenticatedRequest>();
    const apiKey = this.header(request, 'x-api-key');
    const timestamp = this.header(request, 'x-timestamp');
    const signature = this.header(request, 'x-signature');
    if (!apiKey || !timestamp || !signature) {
      throw new UnauthorizedException('Missing X-API-KEY / X-TIMESTAMP / X-SIGNATURE headers');
    }

    const ts = Number.parseInt(timestamp, 10);
    if (!Number.isFinite(ts) || Math.abs(Date.now() - ts) > MAX_CLOCK_SKEW_MS) {
      throw new UnauthorizedException('Request timestamp outside allowed window');
    }

    const prefix = apiKey.slice(0, 17); // 'orz_live_' + 8 hex chars
    const row = await this.db.queryOne<ApiKeyRow>(
      `SELECT k.id, k.merchant_id, k.key_hash, k.secret_encrypted, k.permissions, k.revoked_at,
              m.status AS merchant_status, m.role AS merchant_role
         FROM api_keys k
         JOIN merchants m ON m.id = k.merchant_id
        WHERE k.key_prefix = $1`,
      [prefix],
    );
    if (!row || row.revoked_at !== null) {
      throw new UnauthorizedException('Unknown or revoked API key');
    }
    if (!timingSafeEqualStr(sha256Hex(apiKey), row.key_hash)) {
      throw new UnauthorizedException('Invalid API key');
    }
    if (row.merchant_status !== 'ACTIVE') {
      throw new ForbiddenException('Merchant account suspended');
    }

    const secret = decryptSecret(row.secret_encrypted, this.config.appEncryptionKey);
    const bodyHash = sha256Hex(request.rawBody ?? Buffer.alloc(0));
    const canonical = `${timestamp}.${request.method.toUpperCase()}.${request.originalUrl.split('?')[0]}.${bodyHash}`;
    const expected = hmacSha256Hex(secret, canonical);
    if (!timingSafeEqualStr(expected, signature.toLowerCase())) {
      throw new UnauthorizedException('Invalid request signature');
    }

    const needed = this.reflector.getAllAndOverride<string | undefined>(PERMISSION_KEY, [
      context.getHandler(),
      context.getClass(),
    ]);
    if (needed && !row.permissions.includes(needed)) {
      throw new ForbiddenException(`API key lacks permission: ${needed}`);
    }

    request.principal = {
      merchantId: row.merchant_id,
      role: row.merchant_role,
      apiKeyId: row.id,
      permissions: row.permissions,
    };
    // fire-and-forget usage tracking
    void this.db
      .query('UPDATE api_keys SET last_used_at = now() WHERE id = $1', [row.id])
      .catch(() => undefined);
    return true;
  }

  private header(request: AuthenticatedRequest, name: string): string | undefined {
    const value = request.headers[name];
    return Array.isArray(value) ? value[0] : value;
  }
}
