import { createParamDecorator, ExecutionContext } from '@nestjs/common';
import { AuthenticatedRequest, AuthPrincipal } from '../types';

export const CurrentPrincipal = createParamDecorator(
  (_data: unknown, context: ExecutionContext): AuthPrincipal => {
    const request = context.switchToHttp().getRequest<AuthenticatedRequest>();
    return request.principal;
  },
);
