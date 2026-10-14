import { HiTechPayConnectionError, errorFromResponse } from './errors';

export const DEFAULT_BASE_URL = 'https://api.hitechpay.vn';
export const SANDBOX_BASE_URL = 'https://sandbox-api.hitechpay.vn';
export const SDK_VERSION = '0.1.0';

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export type FetchLike = (input: string, init: {
  method: string;
  headers: Record<string, string>;
  body?: string;
  signal?: AbortSignal;
}) => Promise<{
  status: number;
  ok: boolean;
  headers: { forEach(cb: (value: string, key: string) => void): void };
  text(): Promise<string>;
}>;

export interface HiTechPayConfig {
  /** Secret API key (`snd_...` / `prd_...`). Sent in the `api-key` header. */
  apiKey: string;
  /** API base URL. Defaults to https://api.hitechpay.vn. Use `HiTechPay.SANDBOX_BASE_URL` for sandbox. */
  baseUrl?: string;
  /** Per-attempt timeout in milliseconds. Default 30000. */
  timeoutMs?: number;
  /** Max automatic retries for idempotent requests (GET/DELETE). Default 2. */
  maxRetries?: number;
  /** Base delay for exponential backoff in ms. Default 500. */
  retryBaseDelayMs?: number;
  /** Max delay between retries in ms. Default 8000. */
  retryMaxDelayMs?: number;
  /** Custom fetch implementation (defaults to global fetch). */
  fetch?: FetchLike;
  /** Extra headers sent with every request. */
  headers?: Record<string, string>;
}

export interface RequestOptions {
  /** Extra headers for this request. */
  headers?: Record<string, string>;
  /** Override timeout for this request. */
  timeoutMs?: number;
  /** Override max retries for this request. */
  maxRetries?: number;
  /**
   * Force-enable retries for a non-idempotent method (e.g. a POST that you made
   * idempotent yourself by passing a fixed `payment_id` / `refund_id`).
   */
  idempotent?: boolean;
  /** AbortSignal to cancel the request. */
  signal?: AbortSignal;
}

export type QueryParams = Record<string, string | number | boolean | undefined | null>;

const RETRYABLE_STATUS = (status: number): boolean => status === 429 || status >= 500;

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

export function buildQuery(query?: QueryParams): string {
  if (!query) return '';
  const parts: string[] = [];
  for (const [k, v] of Object.entries(query)) {
    if (v === undefined || v === null) continue;
    parts.push(`${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`);
  }
  return parts.length ? `?${parts.join('&')}` : '';
}

/** Encode a path segment (ids are user supplied). */
export function seg(value: string): string {
  if (typeof value !== 'string' || value === '') {
    throw new TypeError('HiTechPay: id must be a non-empty string');
  }
  return encodeURIComponent(value);
}

export class HttpClient {
  readonly baseUrl: string;
  readonly timeoutMs: number;
  readonly maxRetries: number;
  private readonly apiKey: string;
  private readonly retryBaseDelayMs: number;
  private readonly retryMaxDelayMs: number;
  private readonly fetchImpl: FetchLike;
  private readonly defaultHeaders: Record<string, string>;

  constructor(config: HiTechPayConfig) {
    if (!config || typeof config.apiKey !== 'string' || config.apiKey.trim() === '') {
      throw new TypeError('HiTechPay: `apiKey` is required');
    }
    this.apiKey = config.apiKey;
    this.baseUrl = (config.baseUrl ?? DEFAULT_BASE_URL).replace(/\/+$/, '');
    this.timeoutMs = config.timeoutMs ?? 30_000;
    this.maxRetries = Math.max(0, config.maxRetries ?? 2);
    this.retryBaseDelayMs = config.retryBaseDelayMs ?? 500;
    this.retryMaxDelayMs = config.retryMaxDelayMs ?? 8_000;
    const f = config.fetch ?? (globalThis.fetch as unknown as FetchLike | undefined);
    if (typeof f !== 'function') {
      throw new TypeError('HiTechPay: global fetch is not available; use Node >= 18 or pass `fetch`');
    }
    this.fetchImpl = f;
    this.defaultHeaders = { ...(config.headers ?? {}) };
  }

  async request<T>(
    method: HttpMethod,
    path: string,
    opts: { body?: unknown; query?: QueryParams } & RequestOptions = {},
  ): Promise<T> {
    const url = `${this.baseUrl}${path}${buildQuery(opts.query)}`;
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'User-Agent': `hitechpay-node/${SDK_VERSION} node/${typeof process !== 'undefined' ? process.version : 'unknown'}`,
      ...this.defaultHeaders,
      ...(opts.headers ?? {}),
      'api-key': this.apiKey,
    };
    let body: string | undefined;
    if (opts.body !== undefined && method !== 'GET') {
      body = JSON.stringify(opts.body);
      headers['Content-Type'] = 'application/json';
    }

    const idempotent = opts.idempotent ?? (method === 'GET' || method === 'DELETE');
    const maxRetries = idempotent ? Math.max(0, opts.maxRetries ?? this.maxRetries) : 0;
    const timeoutMs = opts.timeoutMs ?? this.timeoutMs;

    for (let attempt = 0; ; attempt++) {
      const controller = new AbortController();
      const onAbort = () => controller.abort();
      if (opts.signal) {
        if (opts.signal.aborted) controller.abort();
        else opts.signal.addEventListener('abort', onAbort, { once: true });
      }
      let timedOut = false;
      const timer = setTimeout(() => {
        timedOut = true;
        controller.abort();
      }, timeoutMs);

      let status: number;
      let text: string;
      let resHeaders: Record<string, string>;
      try {
        const res = await this.fetchImpl(url, { method, headers, body, signal: controller.signal });
        status = res.status;
        resHeaders = {};
        res.headers.forEach((v, k) => {
          resHeaders[k.toLowerCase()] = v;
        });
        text = await res.text();
      } catch (err) {
        clearTimeout(timer);
        opts.signal?.removeEventListener('abort', onAbort);
        if (opts.signal?.aborted) {
          throw new HiTechPayConnectionError({ message: 'HiTechPay request aborted', cause: err });
        }
        if (attempt < maxRetries) {
          await sleep(this.backoff(attempt));
          continue;
        }
        throw new HiTechPayConnectionError({
          message: timedOut
            ? `HiTechPay request timed out after ${timeoutMs}ms`
            : `HiTechPay network error: ${(err as Error)?.message ?? String(err)}`,
          type: timedOut ? 'timeout' : 'network',
          cause: err,
        });
      }
      clearTimeout(timer);
      opts.signal?.removeEventListener('abort', onAbort);

      let parsed: unknown = text;
      if (text !== '') {
        try {
          parsed = JSON.parse(text);
        } catch {
          parsed = text;
        }
      } else {
        parsed = undefined;
      }

      if (status >= 200 && status < 300) {
        return parsed as T;
      }

      if (RETRYABLE_STATUS(status) && attempt < maxRetries) {
        await sleep(this.backoff(attempt, resHeaders['retry-after']));
        continue;
      }
      throw errorFromResponse(status, parsed, resHeaders);
    }
  }

  /** Exponential backoff with full jitter, honouring Retry-After (seconds). */
  private backoff(attempt: number, retryAfter?: string): number {
    if (retryAfter) {
      const secs = Number(retryAfter);
      if (Number.isFinite(secs) && secs >= 0) return Math.min(secs * 1000, this.retryMaxDelayMs);
    }
    const exp = Math.min(this.retryBaseDelayMs * 2 ** attempt, this.retryMaxDelayMs);
    return exp / 2 + Math.random() * (exp / 2);
  }
}
