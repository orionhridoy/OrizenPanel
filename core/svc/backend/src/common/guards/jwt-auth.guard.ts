import {
  CanActivate,
  ExecutionContext,
  Inject,
  Injectable,
  UnauthorizedException,
} from '@nestjs/common';
import * as jwt from 'jsonwebtoken';
import { APP_CONFIG, AppConfig } from '../../config/config';
import { AuthenticatedRequest } from '../types';

export interface AccessTokenPayload {
  sub: string;
  role: 'MERCHANT' | 'ADMIN';
  type: 'access';
}

@Injectable()
export class JwtAuthGuard implements CanActivate {
  constructor(@Inject(APP_CONFIG) private readonly config: AppConfig) {}

  canActivate(context: ExecutionContext): boolean {
    const request = context.switchToHttp().getRequest<AuthenticatedRequest>();
    const header = request.headers.authorization;
    if (!header?.startsWith('Bearer ')) {
      throw new UnauthorizedException('Missing bearer token');
    }
    const token = header.slice('Bearer '.length);
    let payload: AccessTokenPayload;
    try {
      payload = jwt.verify(token, this.config.jwtAccessSecret, {
        algorithms: ['HS256'],
      }) as AccessTokenPayload;
    } catch {
      throw new UnauthorizedException('Invalid or expired token');
    }
    if (payload.type !== 'access' || !payload.sub) {
      throw new UnauthorizedException('Invalid token type');
    }
    request.principal = { merchantId: payload.sub, role: payload.role };
    return true;
  }
}
