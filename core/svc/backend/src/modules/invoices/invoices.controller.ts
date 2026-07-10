import {
  Body,
  Controller,
  Get,
  Param,
  ParseUUIDPipe,
  Post,
  Query,
  Res,
  UseGuards,
} from '@nestjs/common';
import { Response } from 'express';
import { InvoicesService, InvoiceView } from './invoices.service';
import { CreateInvoiceDto, ListInvoicesDto } from './invoices.dto';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { ApiKeyGuard, RequirePermission } from '../../common/guards/api-key.guard';
import { CurrentPrincipal } from '../../common/decorators/current-principal.decorator';
import { AuthPrincipal } from '../../common/types';

/** External integrations - API-key HMAC auth. */
@Controller('merchant/invoices')
@UseGuards(ApiKeyGuard)
export class MerchantInvoicesController {
  constructor(private readonly invoices: InvoicesService) {}

  @Post()
  @RequirePermission('invoices:write')
  async create(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: CreateInvoiceDto,
  ): Promise<InvoiceView> {
    return this.invoices.create(principal.merchantId, {
      assetCode: dto.assetCode,
      amountDecimal: dto.amount,
      fiatAmount: dto.fiatAmount,
      fiatCurrency: dto.fiatCurrency,
      orderId: dto.orderId,
      description: dto.description,
      redirectUrl: dto.redirectUrl,
      metadata: dto.metadata,
      expiresInMinutes: dto.expiresInMinutes,
    });
  }

  @Get()
  @RequirePermission('invoices:read')
  async list(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Query() query: ListInvoicesDto,
  ): Promise<{ items: InvoiceView[]; total: number }> {
    return this.invoices.list(principal.merchantId, query);
  }

  @Get(':id')
  @RequirePermission('invoices:read')
  async get(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('id', ParseUUIDPipe) invoiceId: string,
  ): Promise<InvoiceView> {
    return this.invoices.getForMerchant(principal.merchantId, invoiceId);
  }
}

/** Dashboard SPA - JWT auth. */
@Controller('dashboard/invoices')
@UseGuards(JwtAuthGuard)
export class DashboardInvoicesController {
  constructor(private readonly invoices: InvoicesService) {}

  @Post()
  async create(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: CreateInvoiceDto,
  ): Promise<InvoiceView> {
    return this.invoices.create(principal.merchantId, {
      assetCode: dto.assetCode,
      amountDecimal: dto.amount,
      fiatAmount: dto.fiatAmount,
      fiatCurrency: dto.fiatCurrency,
      orderId: dto.orderId,
      description: dto.description,
      redirectUrl: dto.redirectUrl,
      metadata: dto.metadata,
      expiresInMinutes: dto.expiresInMinutes,
    });
  }

  @Get()
  async list(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Query() query: ListInvoicesDto,
  ): Promise<{ items: InvoiceView[]; total: number }> {
    return this.invoices.list(principal.merchantId, query);
  }

  @Get(':id')
  async get(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('id', ParseUUIDPipe) invoiceId: string,
  ): Promise<InvoiceView> {
    return this.invoices.getForMerchant(principal.merchantId, invoiceId);
  }

  @Get(':id/payments')
  async payments(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('id', ParseUUIDPipe) invoiceId: string,
  ): Promise<Array<Record<string, unknown>>> {
    return this.invoices.paymentsOf(principal.merchantId, invoiceId);
  }
}

/** Customer checkout - unauthenticated, rate-limited at nginx. */
@Controller('public/invoices')
export class PublicInvoicesController {
  constructor(private readonly invoices: InvoicesService) {}

  @Get(':id')
  async get(
    @Param('id', ParseUUIDPipe) invoiceId: string,
  ): Promise<InvoiceView & { merchantName: string }> {
    return this.invoices.getPublic(invoiceId);
  }

  /**
   * Server-sent events: pushes invoice status every 3 s until it reaches a
   * terminal state (or 30 min passes). nginx keeps buffering off on /public/.
   */
  @Get(':id/events')
  async events(
    @Param('id', ParseUUIDPipe) invoiceId: string,
    @Res() response: Response,
  ): Promise<void> {
    const initial = await this.invoices.publicStatus(invoiceId);
    if (!initial) {
      response.status(404).json({ error: { code: 404, message: 'invoice not found' } });
      return;
    }
    response.status(200);
    response.setHeader('content-type', 'text/event-stream');
    response.setHeader('cache-control', 'no-cache');
    response.setHeader('connection', 'keep-alive');
    response.flushHeaders();

    const terminal = new Set(['PAID', 'OVERPAID', 'UNDERPAID', 'EXPIRED', 'INVALID']);
    const started = Date.now();
    const send = (data: unknown): void => {
      response.write(`data: ${JSON.stringify(data)}\n\n`);
    };
    send(initial);

    const timer = setInterval(() => {
      void (async () => {
        const status = await this.invoices.publicStatus(invoiceId);
        if (!status) {
          clearInterval(timer);
          response.end();
          return;
        }
        send(status);
        if (terminal.has(status.status) || Date.now() - started > 30 * 60_000) {
          clearInterval(timer);
          response.end();
        }
      })().catch(() => {
        clearInterval(timer);
        response.end();
      });
    }, 3000);
    response.on('close', () => clearInterval(timer));
  }
}
