/**
 * Fire a message through the Telegram Bot API. Fixed host (api.telegram.org), so no SSRF surface.
 * Returns true only when Telegram acknowledges delivery. Never throws.
 */
export async function sendTelegramMessage(botToken: string, chatId: string, text: string): Promise<boolean> {
  if (!botToken || !chatId) return false;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 8000);
  try {
    const res = await fetch(`https://api.telegram.org/bot${botToken}/sendMessage`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ chat_id: chatId, text, disable_web_page_preview: true }),
      signal: controller.signal,
    });
    if (!res.ok) return false;
    const json = (await res.json()) as { ok?: boolean };
    return json.ok === true;
  } catch {
    return false;
  } finally {
    clearTimeout(timer);
  }
}

/** A Telegram bot token looks like `123456789:AA...` (numeric id, colon, 35+ url-safe chars). */
export function looksLikeBotToken(token: string): boolean {
  return /^\d{6,}:[A-Za-z0-9_-]{30,}$/.test(token);
}
