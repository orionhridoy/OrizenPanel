import { Injectable, Logger } from '@nestjs/common';
import { PoolClient } from 'pg';
import { DatabaseService } from '../../database/database.service';

export interface AuditEvent {
  actorType: 'MERCHANT' | 'ADMIN' | 'API_KEY' | 'SYSTEM';
  actorId?: string | null;
  action: string;
  resourceType?: string;
  resourceId?: string;
  ip?: string | null;
  userAgent?: string | null;
  metadata?: Record<string, unknown>;
}

@Injectable()
export class AuditService {
  private readonly logger = new Logger(AuditService.name);

  constructor(private readonly db: DatabaseService) {}

  /** Append to the immutable audit trail. Never throws into business flow. */
  async log(event: AuditEvent, client?: PoolClient): Promise<void> {
    const sql = `INSERT INTO audit_logs
        (actor_type, actor_id, action, resource_type, resource_id, ip, user_agent, metadata)
        VALUES ($1, $2, $3, $4, $5, $6, $7, $8)`;
    const params = [
      event.actorType,
      event.actorId ?? null,
      event.action,
      event.resourceType ?? null,
      event.resourceId ?? null,
      event.ip ?? null,
      event.userAgent ?? null,
      JSON.stringify(event.metadata ?? {}),
    ];
    try {
      if (client) {
        await client.query(sql, params);
      } else {
        await this.db.query(sql, params);
      }
    } catch (err) {
      // an audit failure must not break the audited operation, but it is loud
      this.logger.error(`AUDIT WRITE FAILED for ${event.action}: ${(err as Error).message}`);
    }
  }
}
