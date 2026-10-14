import { HttpClient, RequestOptions, seg } from '../http';
import type {
  Customer,
  CustomerCreateParams,
  CustomerDeleteResponse,
  CustomerListParams,
  CustomerUpdateParams,
} from '../types';

export class Customers {
  constructor(private readonly http: HttpClient) {}

  /** POST /customers */
  async create(params: CustomerCreateParams, options?: RequestOptions): Promise<Customer> {
    return this.http.request('POST', '/customers', { body: params, ...options });
  }

  /** GET /customers/{customer_id} */
  async retrieve(customerId: string, options?: RequestOptions): Promise<Customer> {
    return this.http.request('GET', `/customers/${seg(customerId)}`, options);
  }

  /** POST /customers/{customer_id} */
  async update(customerId: string, params: CustomerUpdateParams, options?: RequestOptions): Promise<Customer> {
    return this.http.request('POST', `/customers/${seg(customerId)}`, { body: params, ...options });
  }

  /** DELETE /customers/{customer_id} */
  async delete(customerId: string, options?: RequestOptions): Promise<CustomerDeleteResponse> {
    return this.http.request('DELETE', `/customers/${seg(customerId)}`, options);
  }

  /** GET /customers/list */
  async list(params?: CustomerListParams, options?: RequestOptions): Promise<Customer[]> {
    return this.http.request('GET', '/customers/list', { query: params, ...options });
  }
}
