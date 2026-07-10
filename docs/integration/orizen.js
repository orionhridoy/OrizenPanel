/**
 * Orizen Pay - Node.js server SDK  (Node 18+, zero dependencies)
 *
 * Put this file on YOUR store's server. It signs every request with your API
 * key + secret (HMAC), so the API key/secret NEVER touch the browser.
 *
 *   const { Orizen } = require('./orizen');
 *   const nvx = new Orizen({
 *     baseUrl: 'https://your-gateway-domain',   // e.g. https://45.142.201.26
 *     apiKey:  process.env.ORIZEN_API_KEY,       // orz_live_...
 *     apiSecret: process.env.ORIZEN_API_SECRET,  // shown once when you create the key
 *   });
 */
'use strict';
const crypto = require('crypto');

class OrizenError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

class Orizen {
  constructor({ baseUrl, apiKey, apiSecret }) {
    if (!baseUrl || !apiKey || !apiSecret) {
      throw new Error('Orizen: baseUrl, apiKey and apiSecret are required');
    }
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.apiKey = apiKey;
    this.apiSecret = apiSecret;
  }

  // -- request signing --------------------------------------------------------
  _headers(method, path, body) {
    const timestamp = Date.now().toString();
    const bodyHash = crypto.createHash('sha256').update(body || '').digest('hex');
    const canonical = `${timestamp}.${method.toUpperCase()}.${path}.${bodyHash}`;
    const signature = crypto.createHmac('sha256', this.apiSecret).update(canonical).digest('hex');
    return {
      'X-API-KEY': this.apiKey,
      'X-TIMESTAMP': timestamp,
      'X-SIGNATURE': signature,
      'content-type': 'application/json',
    };
  }

  async _request(method, path, bodyObj) {
    const body = bodyObj === undefined ? '' : JSON.stringify(bodyObj);
    const res = await fetch(this.baseUrl + path, {
      method,
      headers: this._headers(method, path, body),
      body: body || undefined,
    });
    const text = await res.text();
    const data = text ? JSON.parse(text) : undefined;
    if (!res.ok) {
      const msg = data && data.error ? data.error.message : `request failed (${res.status})`;
      throw new OrizenError(res.status, Array.isArray(msg) ? msg.join(', ') : String(msg));
    }
    return data;
  }

  // -- store credit ------------------------------------------------------------

  /** Create a hosted top-up session for one customer. Returns { url, token, ... }.
   *  Send the customer's browser to `url` so they can add funds. */
  createStoreSession({ externalRef, displayName, email }) {
    return this._request('POST', '/api/v1/merchant/store/sessions', { externalRef, displayName, email });
  }

  /** Charge a customer's store balance (e.g. when they buy a tool).
   *  Debits their balance, credits your gateway balance. Idempotent via idempotencyKey. */
  charge({ externalRef, assetCode, amount, description, idempotencyKey }) {
    return this._request('POST', '/api/v1/merchant/store/purchase', {
      externalRef, assetCode, amount, description, idempotencyKey,
    });
  }

  /** Get a customer's per-asset store balances. */
  getStoreBalances(externalRef) {
    return this._request('GET', `/api/v1/merchant/store/users/${encodeURIComponent(externalRef)}/balances`);
  }

  // -- plain invoices (pay-per-order, credits YOUR balance directly) -----------

  /** Create a one-off invoice. Returns { checkoutUrl, address, amountDueDecimal, ... }. */
  createInvoice({ assetCode, amount, orderId, description, redirectUrl, metadata }) {
    return this._request('POST', '/api/v1/merchant/invoices', {
      assetCode, amount, orderId, description, redirectUrl, metadata,
    });
  }

  getInvoice(invoiceId) {
    return this._request('GET', `/api/v1/merchant/invoices/${invoiceId}`);
  }

  getBalances() {
    return this._request('GET', '/api/v1/merchant/balances');
  }

  // -- webhooks ----------------------------------------------------------------

  /**
   * Verify an incoming webhook. Pass the RAW request body (string/Buffer) and the
   * X-Orizen-Signature / X-Orizen-Timestamp headers, plus the endpoint secret
   * (whsec_...) shown when you created the webhook endpoint. Returns true/false.
   */
  static verifyWebhook(rawBody, signatureHeader, timestampHeader, endpointSecret) {
    if (!signatureHeader || !timestampHeader) return false;
    const provided = signatureHeader.replace(/^v1=/, '');
    const body = Buffer.isBuffer(rawBody) ? rawBody.toString('utf8') : String(rawBody);
    const expected = crypto
      .createHmac('sha256', endpointSecret)
      .update(`${timestampHeader}.${body}`)
      .digest('hex');
    const a = Buffer.from(expected);
    const b = Buffer.from(provided);
    return a.length === b.length && crypto.timingSafeEqual(a, b);
  }
}

module.exports = { Orizen, OrizenError };
