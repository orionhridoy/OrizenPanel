import { get } from 'http';

const port = Number.parseInt(process.env.SIGNER_PORT ?? '4000', 10);
const request = get({ host: '127.0.0.1', port, path: '/health', timeout: 4000 }, (response) => {
  process.exit(response.statusCode === 200 ? 0 : 1);
});
request.on('timeout', () => {
  request.destroy();
  process.exit(1);
});
request.on('error', () => process.exit(1));
