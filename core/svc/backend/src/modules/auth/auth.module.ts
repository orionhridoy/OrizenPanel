import { Module } from '@nestjs/common';
import { AuthController, AdminSettingsController } from './auth.controller';
import { AuthService } from './auth.service';

@Module({
  controllers: [AuthController, AdminSettingsController],
  providers: [AuthService],
  exports: [AuthService],
})
export class AuthModule {}
