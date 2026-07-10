import { CanActivate, ExecutionContext, ForbiddenException, Injectable } from '@nestjs/common';
import { AuthenticatedRequest } from '../types';

/** Use AFTER JwtAuthGuard: @UseGuards(JwtAuthGuard, AdminGuard) */
@Injectable()
export class AdminGuard implements CanActivate {
  canActivate(context: ExecutionContext): boolean {
    const request = context.switchToHttp().getRequest<AuthenticatedRequest>();
    if (request.principal?.role !== 'ADMIN') {
      throw new ForbiddenException('Admin role required');
    }
    return true;
  }
}
