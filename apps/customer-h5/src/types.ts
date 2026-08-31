import type {
  FulfillmentStatus,
  FulfillmentType,
  MealType,
  OrderStatus,
} from '@budapest/shared'
import type { OrderItemDto } from '@budapest/api-types'

export type { FulfillmentType } from '@budapest/shared'
export interface Session { id: number; service_date: string; meal_type: MealType; cutoff_at: string }
export interface Product { id: number; type: 'dish' | 'package'; name: string; description?: string; image_url?: string; category_id: number; category_name: string; sale_price: string; available_stock: number | null; sold_out: boolean }
export interface TimeSlot { id: number; fulfillment_type: FulfillmentType; start_time: string; end_time: string; capacity: number; used_capacity: number }
export interface MenuData { session: Session; products: Product[]; time_slots: TimeSlot[] }
export interface CartItem { product: Product; quantity: number }
export interface Order { order_no: string; fulfillment_code?: string; table_no?: string; query_token?: string; status: OrderStatus; fulfillment_status: FulfillmentStatus; service_date: string; meal_type: MealType; fulfillment_type: FulfillmentType; payable_amount: string; customer_name: string; customer_phone: string; remark?: string; items: OrderItemDto[] }
