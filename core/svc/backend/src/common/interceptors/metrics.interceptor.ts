import { CallHandler, ExecutionContext, Injectable, NestInterceptor } from '@nestjs/common';
import { Observable } from 'rxjs';
import { tap } from 'rxjs/operators';
import { Request, Response } from 'express';
import { MetricsService } from '../../modules/metrics/metrics.service';

@Injectable()
export class MetricsInterceptor implements NestInterceptor {
  constructor(private readonly metrics: MetricsService) {}

  intercept(context: ExecutionContext, next: CallHandler): Observable<unknown> {
    const start = process.hrtime.bigint();
    const http = context.switchToHttp();
    const request = http.getRequest<Request>();
    // route template, not raw URL - avoids label cardinality explosion
    const route = request.route?.path ?? 'unmatched';

    return next.handle().pipe(
      tap({
        finalize: () => {
          const response = http.getResponse<Response>();
          const seconds = Number(process.hrtime.bigint() - start) / 1e9;
          this.metrics.httpRequestDuration
            .labels(request.method, route, String(response.statusCode))
            .observe(seconds);
        },
      }),
    );
  }
}
