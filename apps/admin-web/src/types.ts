export interface AdminUser {
  id: number
  username: string
  role_code: string
}
export interface AdminAccount extends AdminUser {
  status: string
  last_login_at?: string
  created_at: string
}
export interface OperationLog {
  id: number
  user_id?: number
  username?: string
  action: string
  target_type?: string
  target_id?: string
  ip?: string
  detail?: string
  created_at: string
}
export interface AuthData {
  access_token: string
  expires_in: number
  user: AdminUser
}
export interface Metrics {
  order_count: number
  people_count: string
  subtotal_amount: string
  delivery_fee: string
  payable_amount: string
  paid_amount: string
}
export interface Dashboard {
  date: string
  metrics: Metrics
  status_distribution: Record<string, number>
  fulfillment_distribution: Record<string, number>
  product_ranking: Array<{
    product_id: number
    product_name: string
    quantity: string
    amount: string
  }>
  slot_capacity: Array<{
    id: number
    meal_type: string
    fulfillment_type: string
    start_time: string
    end_time: string
    capacity: number
    used_capacity: number
  }>
  alerts: Array<{
    id: number
    order_no: string
    fulfillment_code?: string
    fulfillment_type: string
    status: string
    payment_status: string
    customer_name: string
    payable_amount: string
    updated_at: string
    level: string
    reason: string
  }>
}
export interface OrderItem {
  id: number
  product_name: string
  unit_price: string
  quantity: number
  total_amount: string
}
export interface StatusLog {
  id: number
  from_status: string | null
  to_status: string
  reason?: string
  created_at: string
}
export interface Order {
  id: number
  order_no: string
  fulfillment_code?: string
  session_id: number
  time_slot_id: number
  table_id?: number
  table_no?: string
  service_date: string
  meal_type: string
  fulfillment_type: string
  fulfillment_status: string
  status: string
  payment_status: string
  customer_name: string
  customer_phone: string
  address?: string
  people_count: number
  remark?: string
  subtotal_amount?: string
  delivery_fee?: string
  discount_amount?: string
  payable_amount: string
  cancel_reason?: string
  created_at: string
  items?: OrderItem[]
  status_logs?: StatusLog[]
}
export interface PageData<T> {
  total: number
  per_page: number
  current_page: number
  last_page: number
  data: T[]
}
export type ResourceName =
  'categories' | 'products' | 'sessions' | 'time-slots' | 'delivery-zones' | 'tables'
