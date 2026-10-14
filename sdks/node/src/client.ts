import {
  DEFAULT_BASE_URL,
  HiTechPayConfig,
  HttpClient,
  HttpMethod,
  QueryParams,
  RequestOptions,
  SANDBOX_BASE_URL,
  SDK_VERSION,
} from './http';
import { Customers } from './resources/customers';
import { Disputes, Mandates, PaymentLinks, PaymentMethods } from './resources/misc';
import { Payments } from './resources/payments';
import { Refunds } from './resources/refunds';
import { webhooks } from './webhooks';
import { vn } from './vn';

export class HiTechPay {
  static readonly DEFAULT_BASE_URL = DEFAULT_BASE_URL;
  static readonly SANDBOX_BASE_URL = SANDBOX_BASE_URL;
  static readonly VERSION = SDK_VERSION;
  /** Webhook helpers (static, no client needed): `HiTechPay.webhooks.verify(...)`. */
  static readonly webhooks = webhooks;
  /** Vietnam helpers: `HiTechPay.vn.formatVnd(150000)`. */
  static readonly vn = vn;

  readonly payments: Payments;
  readonly refunds: Refunds;
  readonly customers: Customers;
  readonly paymentMethods: PaymentMethods;
  readonly paymentLinks: PaymentLinks;
  readonly mandates: Mandates;
  readonly disputes: Disputes;
  /** Same as `HiTechPay.webhooks`, available on instances for convenience. */
  readonly webhooks = webhooks;
  readonly vn = vn;

  private readonly http: HttpClient;

  constructor(config: HiTechPayConfig) {
    this.http = new HttpClient(config);
    this.payments = new Payments(this.http);
    this.refunds = new Refunds(this.http);
    this.customers = new Customers(this.http);
    this.paymentMethods = new PaymentMethods(this.http);
    this.paymentLinks = new PaymentLinks(this.http);
    this.mandates = new Mandates(this.http);
    this.disputes = new Disputes(this.http);
  }

  get baseUrl(): string {
    return this.http.baseUrl;
  }

  /** Low-level escape hatch for endpoints not wrapped by the SDK. */
  request<T = unknown>(
    method: HttpMethod,
    path: string,
    opts?: { body?: unknown; query?: QueryParams } & RequestOptions,
  ): Promise<T> {
    return this.http.request<T>(method, path.startsWith('/') ? path : `/${path}`, opts);
  }
}
