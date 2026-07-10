import {
  IsBoolean,
  IsEmail,
  IsNotEmpty,
  IsString,
  Length,
  Matches,
  MaxLength,
  MinLength,
} from 'class-validator';

export class RegistrationToggleDto {
  @IsBoolean()
  enabled!: boolean;
}

export class RegisterDto {
  @IsEmail()
  @MaxLength(254)
  email!: string;

  @IsString()
  @MinLength(12, { message: 'password must be at least 12 characters' })
  @MaxLength(128)
  password!: string;

  @IsString()
  @IsNotEmpty()
  @MaxLength(120)
  name!: string;
}

export class LoginDto {
  @IsEmail()
  @MaxLength(254)
  email!: string;

  @IsString()
  @MinLength(1)
  @MaxLength(128)
  password!: string;

  @IsString()
  @Length(32, 32)
  @Matches(/^[0-9a-f]{32}$/)
  captchaId!: string;

  @IsString()
  @IsNotEmpty()
  @MaxLength(16)
  captcha!: string;
}

export class LoginTwofaDto {
  @IsString()
  @IsNotEmpty()
  @MaxLength(1024)
  ticket!: string;

  @IsString()
  @Length(6, 6)
  @Matches(/^\d{6}$/)
  code!: string;
}

/** Login method-choice: ask for a Telegram code against a pending 2FA ticket. */
export class LoginTwofaSendDto {
  @IsString()
  @IsNotEmpty()
  @MaxLength(1024)
  ticket!: string;
}

/** Turning a factor off is verified by that same factor - a current 6-digit code. */
export class TwofaCodeDto {
  @IsString()
  @Length(6, 6)
  @Matches(/^\d{6}$/)
  code!: string;
}

/** Toggle "require a 2FA code to withdraw". */
export class Withdrawal2faDto {
  @IsBoolean()
  enabled!: boolean;
}

export class TelegramSetupDto {
  @IsString()
  @IsNotEmpty()
  @MaxLength(80)
  botToken!: string;

  @IsString()
  @Matches(/^-?\d{1,20}$/, { message: 'chat ID must be numeric' })
  chatId!: string;
}

export class TelegramEnableDto {
  @IsString()
  @Length(6, 6)
  @Matches(/^\d{6}$/)
  code!: string;
}

export class RefreshDto {
  @IsString()
  @Length(64, 128)
  refreshToken!: string;
}

export class ChangePasswordDto {
  @IsString()
  @MinLength(1)
  currentPassword!: string;

  @IsString()
  @MinLength(12)
  @MaxLength(128)
  newPassword!: string;
}

export class TotpEnableDto {
  @IsString()
  @Length(6, 6)
  @Matches(/^\d{6}$/)
  code!: string;
}

/** Signed by the Orizen control panel (PANEL_SSO_SECRET) - no user password involved. */
export class PanelTokenDto {
  @IsEmail()
  @MaxLength(254)
  email!: string;

  @IsString()
  @Matches(/^\d{1,20}$/)
  ts!: string;

  @IsString()
  @Length(64, 64)
  @Matches(/^[0-9a-f]{64}$/)
  sig!: string;
}

export class PanelResetDto extends PanelTokenDto {
  @IsString()
  @MinLength(12)
  @MaxLength(128)
  newPassword!: string;
}
