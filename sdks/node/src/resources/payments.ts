import { HttpClient, RequestOptions, seg } from '../http';
import type {
  Payment,
  PaymentCancelParams,
  PaymentCaptureParams,
  PaymentConfirmParams,
  PaymentCreateParams,
  PaymentList,
  PaymentListParams,
  PaymentRetrieveParams,
  PaymentUpdateParams,
} from '../types';

export class Payments {
  constructor(private readonly http: HttpClient) {}

  /** POST /payments */
  async create(params: PaymentCreateParams, options?: RequestOptions): Promise<Payment> {
    return this.http.request('POST', '/payments', { body: params, ...options });
  }

  /** GET /payments/{payment_id} */
  async retrieve(paymentId: string, params?: PaymentRetrieveParams, options?: RequestOptions): Promise<Payment> {
    return this.http.request('GET', `/payments/${seg(paymentId)}`, { query: params, ...options });
  }

  /** POST /payments/{payment_id} */
  async update(paymentId: string, params: PaymentUpdateParams, options?: RequestOptions): Promise<Payment> {
    return this.http.request('POST', `/payments/${seg(paymentId)}`, { body: params, ...options });
  }

  /** POST /payments/{payment_id}/confirm */
  async confirm(paymentId: string, params: PaymentConfirmParams = {}, options?: RequestOptions): Promise<Payment> {
    return this.http.request('POST', `/payments/${seg(paymentId)}/confirm`, { body: params, ...options });
  }

  /** POST /payments/{payment_id}/capture */
  async capture(paymentId: string, params: PaymentCaptureParams = {}, options?: RequestOptions): Promise<Payment> {
    return this.http.request('POST', `/payments/${seg(paymentId)}/capture`, { body: params, ...options });
  }

  /** POST /payments/{payment_id}/cancel */
  async cancel(paymentId: string, params: PaymentCancelParams = {}, options?: RequestOptions): Promise<Payment> {
    return this.http.request('POST', `/payments/${seg(paymentId)}/cancel`, { body: params, ...options });
  }

  /** GET /payments/list */
  async list(params?: PaymentListParams, options?: RequestOptions): Promise<PaymentList> {
    return this.http.request('GET', '/payments/list', { query: params, ...options });
  }
}
