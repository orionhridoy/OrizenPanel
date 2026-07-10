import { Module } from '@nestjs/common';
import { WebhookEndpointsController } from './webhooks/webhook-endpoints.controller';
import { MerchantApiController } from './merchants/merchant-api.controller';
import { AuditController } from './audit/audit.controller';
import { NodesController } from './nodes/nodes.controller';
import { WalletsController } from './wallets/wallets.controller';
import { SyncController } from './sync/sync.controller';

/**
 * HTTP controllers that ride on top of the GLOBAL service modules
 * (webhooks/audit/nodes/wallets/ledger). Kept out of those modules so the
 * worker process - which imports the same globals - never exposes them.
 */
@Module({
  controllers: [
    WebhookEndpointsController,
    MerchantApiController,
    AuditController,
    NodesController,
    WalletsController,
    SyncController,
  ],
})
export class ApiControllersModule {}
