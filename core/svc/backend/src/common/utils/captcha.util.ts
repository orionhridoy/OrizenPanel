import { randomInt } from 'crypto';

// Omit ambiguous glyphs (0/O, 1/I) so the code is easy to read off the image.
const CAPTCHA_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

export function newCaptchaCode(len = 5): string {
  let s = '';
  for (let i = 0; i < len; i++) s += CAPTCHA_CHARS[randomInt(0, CAPTCHA_CHARS.length)];
  return s;
}

/** Self-contained distorted-text SVG - no third-party captcha service, nothing leaves the box. */
export function captchaSvg(code: string): string {
  const w = 160;
  const h = 54;
  const esc = (c: string): string =>
    c.replace(/[&<>"]/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[m] as string);
  let s = `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" role="img" aria-label="captcha">`;
  s += `<rect width="100%" height="100%" fill="#f1f5f9"/>`;
  for (let i = 0; i < 5; i++) {
    s += `<line x1="${randomInt(0, w)}" y1="${randomInt(0, h)}" x2="${randomInt(0, w)}" y2="${randomInt(0, h)}" stroke="#cbd5e1" stroke-width="1"/>`;
  }
  let x = 18;
  for (const ch of code) {
    const rot = randomInt(-24, 25);
    const y = randomInt(33, 41);
    const fs = randomInt(26, 34);
    s += `<text x="${x}" y="${y}" font-family="monospace" font-size="${fs}" font-weight="700" fill="#0f172a" transform="rotate(${rot} ${x} ${y})">${esc(ch)}</text>`;
    x += 26;
  }
  for (let i = 0; i < 45; i++) {
    s += `<circle cx="${randomInt(0, w)}" cy="${randomInt(0, h)}" r="1" fill="#94a3b8"/>`;
  }
  return s + '</svg>';
}
