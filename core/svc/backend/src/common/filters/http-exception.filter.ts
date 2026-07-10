import {
  ArgumentsHost,
  Catch,
  ExceptionFilter,
  HttpException,
  HttpStatus,
  Logger,
} from '@nestjs/common';
import { Response } from 'express';

/**
 * Uniform error envelope. Internal errors are logged with a correlation id and
 * never leak stack traces, SQL, or connection strings to the client.
 */
@Catch()
export class HttpExceptionFilter implements ExceptionFilter {
  private readonly logger = new Logger(HttpExceptionFilter.name);

  catch(exception: unknown, host: ArgumentsHost): void {
    const ctx = host.switchToHttp();
    const response = ctx.getResponse<Response>();

    if (exception instanceof HttpException) {
      const status = exception.getStatus();
      const body = exception.getResponse();
      const message =
        typeof body === 'string'
          ? body
          : ((body as Record<string, unknown>).message ?? exception.message);
      response.status(status).json({ error: { code: status, message } });
      return;
    }

    const correlationId = Math.random().toString(36).slice(2, 10);
    this.logger.error(
      `[${correlationId}] ${(exception as Error)?.message ?? 'unknown error'}`,
      (exception as Error)?.stack,
    );
    response.status(HttpStatus.INTERNAL_SERVER_ERROR).json({
      error: {
        code: HttpStatus.INTERNAL_SERVER_ERROR,
        message: `Internal error (ref ${correlationId})`,
      },
    });
  }
}
