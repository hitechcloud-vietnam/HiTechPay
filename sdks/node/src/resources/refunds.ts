import { HttpClient, RequestOptions, seg } from '../http';
import type { Refund, RefundCreateParams, RefundList, RefundListParams, RefundUpdateParams } from '../types';

export class Refunds {
  constructor(private readonly http: HttpClient) {}

  /** POST /refunds */
  async create(params: RefundCreateParams, options?: RequestOptions): Promise<Refund> {
    return this.http.request('POST', '/refunds', { body: params, ...options });
  }

  /** GET /refunds/{refund_id} */
  async retrieve(refundId: string, options?: RequestOptions): Promise<Refund> {
    return this.http.request('GET', `/refunds/${seg(refundId)}`, options);
  }

  /** POST /refunds/{refund_id} */
  async update(refundId: string, params: RefundUpdateParams, options?: RequestOptions): Promise<Refund> {
    return this.http.request('POST', `/refunds/${seg(refundId)}`, { body: params, ...options });
  }

  /**
   * POST /refunds/list — a read-only filter query, so it is retried like a GET
   * unless `options.idempotent` is set to false.
   */
  async list(params: RefundListParams = {}, options?: RequestOptions): Promise<RefundList> {
    return this.http.request('POST', '/refunds/list', { body: params, idempotent: true, ...options });
  }
}
