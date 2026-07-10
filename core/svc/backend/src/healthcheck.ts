/**
 * Docker HEALTHCHECK entrypoint (node dist/healthcheck.js).
 * Exits 0 when the local HTTP server answers, 1 otherwise.
 */
import { get } from 'http';

const port = Number.parseInt(process.env.HTTP_PORT ?? '3000', 10);
const path = process.env.APP_ROLE === 'worker' ? '/health' : '/api/v1/public/health';

const request = get({ host: '127.0.0.1', port, path, timeout: 4000 }, (response) => {
  process.exit(response.statusCode === 200 ? 0 : 1);
});
request.on('timeout', () => {
  request.destroy();
  process.exit(1);
});
request.on('error', () => process.exit(1));
