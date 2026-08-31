export interface ApiResponse<T> {
  code: number
  message: string
  data: T
}

export interface PageData<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface MoneyAmounts {
  subtotal_amount: string
  delivery_fee: string
  discount_amount?: string
  payable_amount: string
}

export interface OrderItemDto {
  id: number
  product_name: string
  unit_price: string
  quantity: number
  total_amount: string
}
