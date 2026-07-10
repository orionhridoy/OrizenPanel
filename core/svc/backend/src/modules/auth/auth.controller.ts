import { Body, Controller, Get, HttpCode, Ip, Patch, Post, Req, UseGuards } from '@nestjs/common';
import { AuthService, TokenPair, TwofaChallenge } from './auth.service';
import {
  ChangePasswordDto,
  LoginDto,
  LoginTwofaDto,
  LoginTwofaSendDto,
  PanelResetDto,
  PanelTokenDto,
  RefreshDto,
  RegisterDto,
  RegistrationToggleDto,
  TelegramEnableDto,
  TelegramSetupDto,
  TotpEnableDto,
  TwofaCodeDto,
  Withdrawal2faDto,
} from './auth.dto';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { AdminGuard } from '../../common/guards/admin.guard';
import { CurrentPrincipal } from '../../common/decorators/current-principal.decorator';
import { AuthPrincipal, AuthenticatedRequest } from '../../common/types';
import { AuditService } from '../audit/audit.service';

@Controller('auth')
export class AuthController {
  constructor(private readonly auth: AuthService) {}

  /** Public: lets the sign-up page show whether registration is open. */
  @Get('registration')
  async registrationStatus(): Promise<{ enabled: boolean }> {
    return { enabled: await this.auth.isRegistrationEnabled() };
  }

  @Post('register')
  async register(@Body() dto: RegisterDto, @Ip() ip: string): Promise<TokenPair> {
    return this.auth.register({ ...dto, ip });
  }

  /** Panel SSO: the control panel (which knows PANEL_SSO_SECRET) exchanges a signed request
   *  for a real session, so "Open" from the panel logs the admin in automatically. */
  @Post('panel-token')
  @HttpCode(200)
  async panelToken(@Body() dto: PanelTokenDto, @Ip() ip: string): Promise<TokenPair> {
    return this.auth.panelLogin(dto.email, dto.ts, dto.sig, ip);
  }

  /** Panel-driven admin password reset (returns nothing sensitive; the panel shows the new one). */
  @Post('panel-reset')
  @HttpCode(200)
  async panelReset(@Body() dto: PanelResetDto, @Ip() ip: string): Promise<{ ok: true }> {
    await this.auth.panelReset(dto.email, dto.ts, dto.sig, dto.newPassword, ip);
    return { ok: true };
  }

  /** Public: fresh captcha challenge for the login form. */
  @Get('captcha')
  async captcha(): Promise<{ id: string; svg: string }> {
    return this.auth.createCaptcha();
  }

  @Post('login')
  @HttpCode(200)
  async login(
    @Body() dto: LoginDto,
    @Ip() ip: string,
    @Req() request: AuthenticatedRequest,
  ): Promise<TokenPair | TwofaChallenge> {
    return this.auth.login({
      ...dto,
      ip,
      userAgent: (request.headers['user-agent'] ?? '').slice(0, 300),
    });
  }

  /** Stage 2 of login: exchange the ticket + second-factor code for real tokens. */
  @Post('login/2fa')
  @HttpCode(200)
  async loginTwofa(
    @Body() dto: LoginTwofaDto,
    @Ip() ip: string,
    @Req() request: AuthenticatedRequest,
  ): Promise<TokenPair> {
    return this.auth.loginTwofa(dto.ticket, dto.code, ip, (request.headers['user-agent'] ?? '').slice(0, 300));
  }

  /** Login method-choice: when both factors are on, the user picks Telegram and we send a code. */
  @Post('login/2fa/send')
  @HttpCode(200)
  async loginTwofaSend(@Body() dto: LoginTwofaSendDto): Promise<{ ok: true }> {
    return this.auth.loginTwofaSend(dto.ticket);
  }

  @Post('refresh')
  @HttpCode(200)
  async refresh(@Body() dto: RefreshDto, @Ip() ip: string): Promise<TokenPair> {
    return this.auth.refresh(dto.refreshToken, ip);
  }

  @Post('logout')
  @HttpCode(204)
  @UseGuards(JwtAuthGuard)
  async logout(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: RefreshDto,
  ): Promise<void> {
    await this.auth.logout(principal.merchantId, dto.refreshToken);
  }

  @Post('change-password')
  @HttpCode(204)
  @UseGuards(JwtAuthGuard)
  async changePassword(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: ChangePasswordDto,
    @Ip() ip: string,
  ): Promise<void> {
    await this.auth.changePassword(principal.merchantId, dto.currentPassword, dto.newPassword, ip);
  }

  @Post('totp/setup')
  @UseGuards(JwtAuthGuard)
  async totpSetup(
    @CurrentPrincipal() principal: AuthPrincipal,
  ): Promise<{ secret: string; otpauthUrl: string }> {
    return this.auth.totpSetup(principal.merchantId);
  }

  @Post('totp/enable')
  @HttpCode(204)
  @UseGuards(JwtAuthGuard)
  async totpEnable(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: TotpEnableDto,
    @Ip() ip: string,
  ): Promise<void> {
    await this.auth.totpEnable(principal.merchantId, dto.code, ip);
  }

  /** Turning off the authenticator is verified by a current app code. */
  @Post('totp/disable')
  @HttpCode(204)
  @UseGuards(JwtAuthGuard)
  async totpDisable(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: TwofaCodeDto,
    @Ip() ip: string,
  ): Promise<void> {
    await this.auth.totpDisable(principal.merchantId, dto.code, ip);
  }

  /** Current second-factor state for the Settings screen. */
  @Get('2fa/status')
  @UseGuards(JwtAuthGuard)
  async twofaStatus(
    @CurrentPrincipal() principal: AuthPrincipal,
  ): Promise<{ totp: boolean; telegram: boolean; telegramChatId: string | null; withdrawal2fa: boolean }> {
    return this.auth.twofaStatus(principal.merchantId);
  }

  /** Toggle "require a 2FA code to withdraw" (needs a 2FA method set up to turn on). */
  @Patch('withdrawal-2fa')
  @UseGuards(JwtAuthGuard)
  async setWithdrawal2fa(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: Withdrawal2faDto,
    @Ip() ip: string,
  ): Promise<{ enabled: boolean }> {
    await this.auth.setWithdrawal2fa(principal.merchantId, dto.enabled, ip);
    return { enabled: dto.enabled };
  }

  /** Ask for a Telegram withdrawal code (when Telegram is the merchant's factor). */
  @Post('withdrawal-2fa/send')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  async sendWithdrawalCode(@CurrentPrincipal() principal: AuthPrincipal): Promise<{ ok: true }> {
    await this.auth.sendWithdrawalTelegramCode(principal.merchantId);
    return { ok: true };
  }

  @Post('telegram/setup')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  async telegramSetup(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: TelegramSetupDto,
  ): Promise<{ ok: true }> {
    return this.auth.telegramSetup(principal.merchantId, dto.botToken, dto.chatId);
  }

  @Post('telegram/enable')
  @HttpCode(204)
  @UseGuards(JwtAuthGuard)
  async telegramEnable(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: TelegramEnableDto,
    @Ip() ip: string,
  ): Promise<void> {
    await this.auth.telegramEnable(principal.merchantId, dto.code, ip);
  }

  /** Step 1 of turning off Telegram 2FA: send a confirmation code to the linked chat. */
  @Post('telegram/disable-send')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  async telegramDisableSend(@CurrentPrincipal() principal: AuthPrincipal): Promise<{ ok: true }> {
    return this.auth.telegramDisableSend(principal.merchantId);
  }

  /** Step 2: turning off Telegram is verified by the code we just sent to Telegram. */
  @Post('telegram/disable')
  @HttpCode(204)
  @UseGuards(JwtAuthGuard)
  async telegramDisable(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: TwofaCodeDto,
    @Ip() ip: string,
  ): Promise<void> {
    await this.auth.telegramDisable(principal.merchantId, dto.code, ip);
  }
}

/** Admin-only controls for gateway-wide settings. */
@Controller('admin/settings')
@UseGuards(JwtAuthGuard, AdminGuard)
export class AdminSettingsController {
  constructor(
    private readonly auth: AuthService,
    private readonly audit: AuditService,
  ) {}

  @Get('registration')
  async getRegistration(): Promise<{ enabled: boolean }> {
    return { enabled: await this.auth.isRegistrationEnabled() };
  }

  @Patch('registration')
  async setRegistration(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: RegistrationToggleDto,
    @Ip() ip: string,
  ): Promise<{ enabled: boolean }> {
    await this.auth.setRegistrationEnabled(dto.enabled);
    await this.audit.log({
      actorType: 'MERCHANT',
      actorId: principal.merchantId,
      action: dto.enabled ? 'admin.registration_enabled' : 'admin.registration_disabled',
      ip,
    });
    return { enabled: dto.enabled };
  }
}
