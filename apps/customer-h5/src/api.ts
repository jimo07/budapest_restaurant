import type { MenuData, Order, Session } from './types'

const API_BASE = import.meta.env.VITE_API_BASE_URL || '/api/v1'

async function request<T>(path: string, options?: RequestInit): Promise<T> {
  const response = await fetch(`${API_BASE}${path}`, { ...options, headers: { 'Content-Type': 'application/json', ...options?.headers } })
  const body = await response.json().catch(() => null)
  if (!response.ok || body?.code !== 0) throw new Error(body?.message || '网络请求失败，请稍后重试')
  return body.data as T
}

export const api = {
  store: (lang = 'zh') => request<Record<string, string>>(`/store?lang=${encodeURIComponent(lang)}`),
  sessions: () => request<Session[]>('/sessions'),
  menu: (id: number, lang = 'zh') => request<MenuData>(`/sessions/${id}/menu?lang=${encodeURIComponent(lang)}`),
  preview: (payload: object) => request<{ amounts: { payable_amount: string; subtotal_amount: string; delivery_fee: string } }>('/orders/preview', { method: 'POST', body: JSON.stringify(payload) }),
  createOrder: (payload: object, lang = 'zh') => request<Order>(`/orders?lang=${encodeURIComponent(lang)}`, { method: 'POST', body: JSON.stringify(payload) }),
  order: (orderNo: string, token: string, lang = 'zh') => request<Order>(`/orders/${encodeURIComponent(orderNo)}?token=${encodeURIComponent(token)}&lang=${encodeURIComponent(lang)}`),
  cancel: (orderNo: string, token: string, lang = 'zh') => request<Order>(`/orders/${encodeURIComponent(orderNo)}/cancel?lang=${encodeURIComponent(lang)}`, { method: 'POST', body: JSON.stringify({ token, reason: '顾客取消' }) }),
}
