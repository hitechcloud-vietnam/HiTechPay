import { HttpClient, QueryParams, RequestOptions, seg } from '../http';
import type {
  CustomerPaymentMethodList,
  Dispute,
  DisputeListParams,
  Mandate,
  MandateRevokeResponse,
  PaymentLink,
} from '../types';

export class PaymentMethods {
  constructor(private readonly http: HttpClient) {}

  /** GET /customers/{customer_id}/payment_methods */
  async list(customerId: string, params?: QueryParams, options?: RequestOptions): Promise<CustomerPaymentMethodList> {
    return this.http.request('GET', `/customers/${seg(customerId)}/payment_methods`, { query: params, ...options });
  }
}

export class PaymentLinks {
  constructor(private readonly http: HttpClient) {}

  /** GET /payment_link/{payment_link_id} */
  async retrieve(paymentLinkId: string, options?: RequestOptions): Promise<PaymentLink> {
    return this.http.request('GET', `/payment_link/${seg(paymentLinkId)}`, options);
  }
}

export class Mandates {
  constructor(private readonly http: HttpClient) {}

  /** GET /mandates/{mandate_id} */
  async retrieve(mandateId: string, options?: RequestOptions): Promise<Mandate> {
    return this.http.request('GET', `/mandates/${seg(mandateId)}`, options);
  }

  /** POST /mandates/revoke/{mandate_id} */
  async revoke(mandateId: string, options?: RequestOptions): Promise<MandateRevokeResponse> {
    return this.http.request('POST', `/mandates/revoke/${seg(mandateId)}`, options);
  }
}

export class Disputes {
  constructor(private readonly http: HttpClient) {}

  /** GET /disputes/{dispute_id} */
  async retrieve(disputeId: string, options?: RequestOptions): Promise<Dispute> {
    return this.http.request('GET', `/disputes/${seg(disputeId)}`, options);
  }

  /** GET /disputes/list */
  async list(params?: DisputeListParams, options?: RequestOptions): Promise<Dispute[]> {
    return this.http.request('GET', '/disputes/list', { query: params, ...options });
  }
}
