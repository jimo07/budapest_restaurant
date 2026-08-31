export type Language = 'zh' | 'en' | 'hu'
export type FulfillmentType = 'delivery' | 'takeaway' | 'dine_in'
export type MealType = 'lunch' | 'dinner'
export type PaymentStatus = 'unpaid' | 'paid' | 'refunded'
export type FulfillmentStatus =
  | 'waiting_delivery'
  | 'delivering'
  | 'delivered'
  | 'waiting_pickup'
  | 'picked_up'
  | 'waiting_arrival'
  | 'seated'
  | 'served'
  | 'dine_completed'
export type OrderStatus =
  | 'pending'
  | 'confirmed'
  | 'preparing'
  | 'ready'
  | 'fulfilling'
  | 'completed'
  | 'cancelled'

export const SUPPORTED_LANGUAGES: readonly Language[] = ['zh', 'en', 'hu']
export const CURRENCY = 'HUF'
export const BUSINESS_TIME_ZONE = 'Europe/Budapest'

export const LANGUAGE_LOCALES: Readonly<Record<Language, string>> = {
  zh: 'zh-CN',
  en: 'en-GB',
  hu: 'hu-HU',
}

export const FULFILLMENT_TYPES: readonly FulfillmentType[] = [
  'delivery',
  'takeaway',
  'dine_in',
]

export const ORDER_STATUS_FLOW: Readonly<Partial<Record<OrderStatus, OrderStatus>>> = {
  pending: 'confirmed',
  confirmed: 'preparing',
  preparing: 'ready',
  ready: 'fulfilling',
  fulfilling: 'completed',
}

export const FULFILLMENT_STATUS_FLOW: Readonly<
  Partial<Record<FulfillmentStatus, FulfillmentStatus>>
> = {
  waiting_delivery: 'delivering',
  delivering: 'delivered',
  waiting_pickup: 'picked_up',
  waiting_arrival: 'seated',
  seated: 'served',
  served: 'dine_completed',
}

export function isLanguage(value: unknown): value is Language {
  return SUPPORTED_LANGUAGES.includes(value as Language)
}

export function languageLocale(language: Language): string {
  return LANGUAGE_LOCALES[language]
}

export function formatHuf(value: string | number | null | undefined): string {
  return new Intl.NumberFormat('hu-HU', {
    style: 'currency',
    currency: CURRENCY,
    maximumFractionDigits: 0,
  }).format(Number(value || 0))
}
