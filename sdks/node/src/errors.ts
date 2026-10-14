/**
 * Error hierarchy for the HiTechPay SDK.
 *
 * Hyperswitch-compatible APIs return errors shaped like:
 *   { "error": { "type": "invalid_request", "message": "...", "code": "IR_06" } }
 */

export interface HiTechPayErrorInit {
  message: string;
  status?: number;
  code?: string;
  type?: string;
  raw?: unknown;
  requestId?: string;
  headers?: Record<string, string>;
  cause?: unknown;
}

export class HiTechPayError extends Error {
  /** HTTP status code (0 when no response was received). */
  readonly status: number;
  /** Machine-readable error code, e.g. `IR_06`, `HE_02`. */
  readonly code?: string;
  /** Error type, e.g. `invalid_request`, `object_not_found`, `api`. */
  readonly type?: string;
  /** Raw response body (parsed JSON when possible, otherwise text). */
  readonly raw?: unknown;
  /** Value of the `x-request-id` response header, if present. */
  readonly requestId?: string;
  /** Response headers (lower-cased names). */
  readonly headers?: Record<string, string>;

  constructor(init: HiTechPayErrorInit) {
    super(init.message);
    this.name = new.target.name;
    this.status = init.status ?? 0;
    this.code = init.code;
    this.type = init.type;
    this.raw = init.raw;
    this.requestId = init.requestId;
    this.headers = init.headers;
    if (init.cause !== undefined) {
      (this as { cause?: unknown }).cause = init.cause;
    }
    Object.setPrototypeOf(this, new.target.prototype);
  }
}

/** 400 / 422 — the request was malformed or failed validation. */
export class HiTechPayInvalidRequestError extends HiTechPayError {}
/** 401 / 403 — missing or invalid API key, or insufficient permissions. */
export class HiTechPayAuthenticationError extends HiTechPayError {}
/** 404 — the requested object does not exist. */
export class HiTechPayNotFoundError extends HiTechPayError {}
/** 409 — conflict, e.g. duplicate payment_id / refund_id. */
export class HiTechPayConflictError extends HiTechPayError {}
/** 429 — too many requests. */
export class HiTechPayRateLimitError extends HiTechPayError {}
/** 5xx — server-side error. */
export class HiTechPayAPIError extends HiTechPayError {}
/** Network failure or timeout; no HTTP response was received (status 0). */
export class HiTechPayConnectionError extends HiTechPayError {}
/** Webhook signature missing or invalid. */
export class HiTechPaySignatureVerificationError extends HiTechPayError {}

interface ErrorBodyShape {
  error?: { type?: string; message?: string; code?: string; reason?: string } | string;
  message?: string;
  code?: string;
  type?: string;
  error_code?: string;
  error_message?: string;
}

/** Build the most specific error subclass for an HTTP error response. */
export function errorFromResponse(
  status: number,
  body: unknown,
  headers: Record<string, string>,
): HiTechPayError {
  let message: string | undefined;
  let code: string | undefined;
  let type: string | undefined;

  if (body && typeof body === 'object') {
    const b = body as ErrorBodyShape;
    if (b.error && typeof b.error === 'object') {
      message = b.error.message ?? b.error.reason;
      code = b.error.code;
      type = b.error.type;
    } else if (typeof b.error === 'string') {
      message = b.error;
    }
    message = message ?? b.message ?? b.error_message;
    code = code ?? b.code ?? b.error_code;
    type = type ?? b.type;
  } else if (typeof body === 'string' && body.trim() !== '') {
    message = body.trim().slice(0, 500);
  }

  const init: HiTechPayErrorInit = {
    message: message ?? `HiTechPay API request failed with HTTP ${status}`,
    status,
    code,
    type,
    raw: body,
    requestId: headers['x-request-id'],
    headers,
  };

  if (status === 400 || status === 422) return new HiTechPayInvalidRequestError(init);
  if (status === 401 || status === 403) return new HiTechPayAuthenticationError(init);
  if (status === 404) return new HiTechPayNotFoundError(init);
  if (status === 409) return new HiTechPayConflictError(init);
  if (status === 429) return new HiTechPayRateLimitError(init);
  if (status >= 500) return new HiTechPayAPIError(init);
  return new HiTechPayError(init);
}
