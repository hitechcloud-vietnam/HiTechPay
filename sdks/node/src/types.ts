/**
 * Request / response types for the HiTechPay (Hyperswitch v1 compatible) API.
 *
 * Only the most commonly used fields are typed. Every object carries an index
 * signature so that new or less common API fields can still be sent/read
 * without casting.
 */

/** Open-ended string union helper: autocompletes known values, accepts any string. */
export type LooseString<T extends string> = T | (string & {});

export type Metadata = Record<string, unknown>;

/** ISO 4217 currency code. Note: VND is a zero-decimal currency (amount 150000 = 150.000 ₫). */
export type Currency = LooseString<'VND' | 'USD' | 'EUR' | 'SGD' | 'THB' | 'JPY'>;

export type PaymentStatus = LooseString<
  | 'succeeded'
  | 'failed'
  | 'cancelled'
  | 'processing'
  | 'requires_customer_action'
  | 'requires_merchant_action'
  | 'requires_payment_method'
  | 'requires_confirmation'
  | 'requires_capture'
  | 'partially_captured'
  | 'partially_captured_and_capturable'
  | 'conflicted'
  | 'expired'
>;

export type PaymentMethod = LooseString<
  | 'card'
  | 'card_redirect'
  | 'wallet'
  | 'bank_redirect'
  | 'bank_transfer'
  | 'real_time_payment'
  | 'pay_later'
  | 'crypto'
  | 'bank_debit'
  | 'reward'
  | 'upi'
  | 'voucher'
  | 'gift_card'
  | 'open_banking'
>;

export type PaymentMethodType = LooseString<
  | 'credit'
  | 'debit'
  | 'momo'
  | 'momo_atm'
  | 'viet_qr'
  | 'local_bank_redirect'
  | 'zalo_pay'
  | 'apple_pay'
  | 'google_pay'
  | 'paypal'
>;

export type CaptureMethod = LooseString<'automatic' | 'manual' | 'manual_multiple' | 'scheduled'>;

export interface Address {
  address?: {
    line1?: string;
    line2?: string;
    line3?: string;
    city?: string;
    state?: string;
    zip?: string;
    country?: string;
    first_name?: string;
    last_name?: string;
    [key: string]: unknown;
  };
  phone?: { number?: string; country_code?: string; [key: string]: unknown };
  email?: string;
  [key: string]: unknown;
}

export interface PaymentMethodData {
  card?: {
    card_number: string;
    card_exp_month: string;
    card_exp_year: string;
    card_holder_name?: string;
    card_cvc: string;
    [key: string]: unknown;
  };
  wallet?: Record<string, unknown>;
  card_redirect?: Record<string, unknown>;
  bank_redirect?: Record<string, unknown>;
  real_time_payment?: Record<string, unknown>;
  billing?: Address;
  [key: string]: unknown;
}

export interface OrderDetail {
  product_name: string;
  quantity: number;
  amount: number;
  product_id?: string;
  [key: string]: unknown;
}

export interface PaymentCreateParams {
  /** Amount in the currency's minor unit (VND has no minor unit). */
  amount: number;
  currency: Currency;
  payment_id?: string;
  confirm?: boolean;
  capture_method?: CaptureMethod;
  customer_id?: string;
  customer?: { id: string; name?: string; email?: string; phone?: string; [key: string]: unknown };
  email?: string;
  name?: string;
  phone?: string;
  phone_country_code?: string;
  description?: string;
  return_url?: string;
  payment_method?: PaymentMethod;
  payment_method_type?: PaymentMethodType;
  payment_method_data?: PaymentMethodData;
  /** Restrict routing to specific connectors, e.g. `["sepay"]`, `["payos"]`, `["momo"]`. */
  connector?: string[];
  billing?: Address;
  shipping?: Address;
  statement_descriptor_name?: string;
  statement_descriptor_suffix?: string;
  order_details?: OrderDetail[];
  metadata?: Metadata;
  setup_future_usage?: LooseString<'off_session' | 'on_session'>;
  authentication_type?: LooseString<'three_ds' | 'no_three_ds'>;
  profile_id?: string;
  session_expiry?: number;
  payment_link?: boolean;
  [key: string]: unknown;
}

export type PaymentUpdateParams = Partial<PaymentCreateParams>;

export interface PaymentConfirmParams {
  payment_method?: PaymentMethod;
  payment_method_type?: PaymentMethodType;
  payment_method_data?: PaymentMethodData;
  return_url?: string;
  client_secret?: string;
  browser_info?: Record<string, unknown>;
  [key: string]: unknown;
}

export interface PaymentCaptureParams {
  amount_to_capture?: number;
  refund_uncaptured_amount?: boolean;
  statement_descriptor_suffix?: string;
  [key: string]: unknown;
}

export interface PaymentCancelParams {
  cancellation_reason?: string;
  [key: string]: unknown;
}

export interface PaymentRetrieveParams {
  /** Force a status sync with the underlying connector. */
  force_sync?: boolean;
  client_secret?: string;
  expand_attempts?: boolean;
  expand_captures?: boolean;
  [key: string]: string | number | boolean | undefined;
}

export interface PaymentListParams {
  customer_id?: string;
  starting_after?: string;
  ending_before?: string;
  limit?: number;
  created?: string;
  'created.lt'?: string;
  'created.gt'?: string;
  'created.lte'?: string;
  'created.gte'?: string;
  [key: string]: string | number | boolean | undefined;
}

export interface RedirectToUrlNextAction {
  type: 'redirect_to_url';
  redirect_to_url: string;
}

export interface QrCodeInformationNextAction {
  type: 'qr_code_information';
  image_data_url?: string;
  qr_code_url?: string;
  display_to_timestamp?: number | null;
  display_text?: string;
  border_color?: string;
  [key: string]: unknown;
}

export interface GenericNextAction {
  type: LooseString<
    | 'redirect_to_url'
    | 'qr_code_information'
    | 'display_bank_transfer_information'
    | 'third_party_sdk_session_token'
    | 'fetch_qr_code_information'
    | 'display_voucher_information'
    | 'wait_screen_information'
    | 'invoke_sdk_client'
  >;
  [key: string]: unknown;
}

export type NextAction = RedirectToUrlNextAction | QrCodeInformationNextAction | GenericNextAction;

export interface Payment {
  payment_id: string;
  merchant_id?: string;
  status: PaymentStatus;
  amount: number;
  net_amount?: number;
  amount_capturable?: number;
  amount_received?: number | null;
  currency: Currency;
  client_secret?: string | null;
  created?: string;
  customer_id?: string | null;
  description?: string | null;
  return_url?: string | null;
  payment_method?: PaymentMethod | null;
  payment_method_type?: PaymentMethodType | null;
  payment_method_data?: Record<string, unknown> | null;
  connector?: string | null;
  connector_transaction_id?: string | null;
  next_action?: NextAction | null;
  error_code?: string | null;
  error_message?: string | null;
  capture_method?: CaptureMethod | null;
  metadata?: Metadata | null;
  refunds?: Refund[] | null;
  disputes?: Dispute[] | null;
  payment_link?: { link: string; payment_link_id: string; [key: string]: unknown } | null;
  profile_id?: string | null;
  [key: string]: unknown;
}

export interface PaymentList {
  size: number;
  data: Payment[];
  [key: string]: unknown;
}

export type RefundStatus = LooseString<'succeeded' | 'failed' | 'pending' | 'review'>;

export interface RefundCreateParams {
  payment_id: string;
  refund_id?: string;
  /** Omit for a full refund. */
  amount?: number;
  reason?: string;
  refund_type?: LooseString<'instant' | 'scheduled'>;
  metadata?: Metadata;
  [key: string]: unknown;
}

export interface RefundUpdateParams {
  reason?: string;
  metadata?: Metadata;
  [key: string]: unknown;
}

export interface RefundListParams {
  payment_id?: string;
  refund_id?: string;
  profile_id?: string;
  limit?: number;
  offset?: number;
  time_range?: { start_time: string; end_time?: string };
  connector?: string[];
  currency?: Currency[];
  refund_status?: RefundStatus[];
  [key: string]: unknown;
}

export interface Refund {
  refund_id: string;
  payment_id: string;
  amount: number;
  currency: Currency;
  status: RefundStatus;
  reason?: string | null;
  metadata?: Metadata | null;
  error_message?: string | null;
  error_code?: string | null;
  created_at?: string;
  updated_at?: string;
  connector?: string;
  [key: string]: unknown;
}

export interface RefundList {
  count?: number;
  total_count?: number;
  data: Refund[];
  [key: string]: unknown;
}

export interface CustomerCreateParams {
  customer_id?: string;
  name?: string;
  email?: string;
  phone?: string;
  phone_country_code?: string;
  description?: string;
  address?: Address['address'];
  metadata?: Metadata;
  [key: string]: unknown;
}

export type CustomerUpdateParams = Omit<CustomerCreateParams, 'customer_id'>;

export interface Customer {
  customer_id: string;
  name?: string | null;
  email?: string | null;
  phone?: string | null;
  phone_country_code?: string | null;
  description?: string | null;
  address?: Address['address'] | null;
  created_at?: string;
  metadata?: Metadata | null;
  default_payment_method_id?: string | null;
  [key: string]: unknown;
}

export interface CustomerListParams {
  limit?: number;
  offset?: number;
  [key: string]: string | number | boolean | undefined;
}

export interface CustomerDeleteResponse {
  customer_id: string;
  customer_deleted: boolean;
  address_deleted?: boolean;
  payment_methods_deleted?: boolean;
  [key: string]: unknown;
}

export interface CustomerPaymentMethod {
  payment_token?: string;
  payment_method_id: string;
  customer_id: string;
  payment_method: PaymentMethod;
  payment_method_type?: PaymentMethodType | null;
  recurring_enabled?: boolean;
  card?: Record<string, unknown> | null;
  created?: string;
  default_payment_method_set?: boolean;
  [key: string]: unknown;
}

export interface CustomerPaymentMethodList {
  customer_payment_methods: CustomerPaymentMethod[];
  is_guest_customer?: boolean;
  [key: string]: unknown;
}

export interface PaymentLink {
  payment_link_id: string;
  merchant_id?: string;
  link_to_pay: string;
  amount: number;
  currency?: Currency | null;
  created_at?: string;
  expiry?: string | null;
  description?: string | null;
  status?: LooseString<'active' | 'expired'>;
  secure_link?: string | null;
  [key: string]: unknown;
}

export interface Mandate {
  mandate_id: string;
  status: LooseString<'active' | 'inactive' | 'pending' | 'revoked'>;
  payment_method_id?: string;
  payment_method?: PaymentMethod;
  payment_method_type?: PaymentMethodType | null;
  customer_acceptance?: Record<string, unknown> | null;
  [key: string]: unknown;
}

export interface MandateRevokeResponse {
  mandate_id: string;
  status: LooseString<'active' | 'inactive' | 'pending' | 'revoked'>;
  error_code?: string | null;
  error_message?: string | null;
  [key: string]: unknown;
}

export interface Dispute {
  dispute_id: string;
  payment_id: string;
  attempt_id?: string;
  amount: string;
  currency: Currency;
  dispute_stage?: LooseString<'pre_dispute' | 'dispute' | 'pre_arbitration'>;
  dispute_status?: LooseString<
    | 'dispute_opened'
    | 'dispute_expired'
    | 'dispute_accepted'
    | 'dispute_cancelled'
    | 'dispute_challenged'
    | 'dispute_won'
    | 'dispute_lost'
  >;
  connector?: string;
  connector_dispute_id?: string;
  connector_reason?: string | null;
  challenge_required_by?: string | null;
  created_at?: string;
  [key: string]: unknown;
}

export interface DisputeListParams {
  limit?: number;
  dispute_status?: string;
  dispute_stage?: string;
  reason?: string;
  connector?: string;
  received_time?: string;
  'received_time.lt'?: string;
  'received_time.gt'?: string;
  'received_time.lte'?: string;
  'received_time.gte'?: string;
  [key: string]: string | number | boolean | undefined;
}

/** Outgoing webhook envelope sent by HiTechPay to the merchant. */
export interface WebhookEvent<T = Record<string, unknown>> {
  merchant_id: string;
  event_id: string;
  event_type: LooseString<
    | 'payment_succeeded'
    | 'payment_failed'
    | 'payment_processing'
    | 'payment_cancelled'
    | 'payment_authorized'
    | 'payment_captured'
    | 'action_required'
    | 'refund_succeeded'
    | 'refund_failed'
    | 'dispute_opened'
    | 'dispute_won'
    | 'dispute_lost'
    | 'mandate_active'
    | 'mandate_revoked'
  >;
  content: {
    type: LooseString<'payment_details' | 'refund_details' | 'dispute_details' | 'mandate_details' | 'payout_details'>;
    object: T;
  };
  timestamp: string;
  [key: string]: unknown;
}
