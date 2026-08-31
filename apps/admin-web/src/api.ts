import type {
  AdminAccount,
  AuthData,
  Dashboard,
  OperationLog,
  Order,
  PageData,
  ResourceName,
} from './types'

const API_BASE = import.meta.env.VITE_API_BASE_URL || '/api/v1'
const currentLanguage = () => localStorage.getItem('budapest-language') || 'zh'
let token = localStorage.getItem('budapest-admin-token') || ''

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  })
  const body = await response.json().catch(() => null)
  if (response.status === 401) {
    token = ''
    localStorage.removeItem('budapest-admin-token')
  }
  if (!response.ok || body?.code !== 0) throw new Error(body?.message || '请求失败，请稍后重试')
  return body.data as T
}

async function download(path: string, fallbackName: string): Promise<void> {
  const response = await fetch(`${API_BASE}${path}`, {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  })
  if (!response.ok) throw new Error('导出失败，请稍后重试')
  const blob = await response.blob()
  const disposition = response.headers.get('Content-Disposition') || ''
  const filename = disposition.match(/filename="?([^";]+)"?/)?.[1] || fallbackName
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}

function query(params: Record<string, string | number | undefined>): string {
  const search = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (value !== '' && value !== undefined) search.set(key, String(value))
  })
  const value = search.toString()
  return value ? `?${value}` : ''
}

export const auth = {
  hasToken: () => Boolean(token),
  login: async (username: string, password: string) => {
    const data = await request<AuthData>('/admin/auth/login', {
      method: 'POST',
      body: JSON.stringify({ username, password }),
    })
    token = data.access_token
    localStorage.setItem('budapest-admin-token', token)
    localStorage.setItem('budapest-admin-user', JSON.stringify(data.user))
    return data
  },
  logout: () => {
    token = ''
    localStorage.removeItem('budapest-admin-token')
    localStorage.removeItem('budapest-admin-user')
  },
}

export const api = {
  store: () => request<Record<string, string>>('/store'),
  dashboard: (date: string) =>
    request<Dashboard>(`/admin/dashboard${query({ date, lang: currentLanguage() })}`),
  orders: (params: Record<string, string | number | undefined>) =>
    request<PageData<Order>>(`/admin/orders${query(params)}`),
  exportOrders: (params: Record<string, string | number | undefined>) =>
    download(
      `/admin/reports/orders.csv${query(params)}`,
      `orders-${String(params.service_date || 'all')}.csv`,
    ),
  users: () => request<PageData<AdminAccount>>('/admin/users?page_size=100'),
  createUser: (payload: Record<string, string>) =>
    request<AdminAccount>('/admin/users', { method: 'POST', body: JSON.stringify(payload) }),
  updateUser: (id: number, payload: Record<string, string>) =>
    request<AdminAccount>(`/admin/users/${id}`, { method: 'PUT', body: JSON.stringify(payload) }),
  logs: (keyword = '') =>
    request<PageData<OperationLog>>(`/admin/logs${query({ keyword, page_size: 100 })}`),
  settings: () => request<Record<string, string>>('/admin/settings'),
  notifications: (since: string) =>
    request<Array<Record<string, unknown>>>(`/admin/notifications${query({ since })}`),
  updateSettings: (payload: Record<string, string>) =>
    request<Record<string, string>>('/admin/settings', {
      method: 'PUT',
      body: JSON.stringify(payload),
    }),
  order: (id: number) =>
    request<Order>(`/admin/orders/${id}?lang=${encodeURIComponent(currentLanguage())}`),
  updateStatus: (id: number, status: string, reason?: string) =>
    request<Order>(`/admin/orders/${id}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ status, reason }),
    }),
  updateFulfillment: (id: number, fulfillment_status: string, table_id?: number) =>
    request<Order>(`/admin/orders/${id}/fulfillment`, {
      method: 'PATCH',
      body: JSON.stringify({ fulfillment_status, table_id }),
    }),
  updatePayment: (id: number, payment_status: string) =>
    request<Order>(`/admin/orders/${id}/payment`, {
      method: 'PATCH',
      body: JSON.stringify({ payment_status }),
    }),
  reschedule: (id: number, time_slot_id: number) =>
    request<Order>(`/admin/orders/${id}/reschedule`, {
      method: 'PATCH',
      body: JSON.stringify({ time_slot_id }),
    }),
  batchStatus: (ids: number[], status: string, reason?: string) =>
    request<Array<{ id: number; success: boolean; message?: string }>>(
      '/admin/orders/batch-status',
      { method: 'POST', body: JSON.stringify({ ids, status, reason }) },
    ),
  workbench: (type: string, date: string) =>
    request<Record<string, unknown>[]>(
      `/admin/workbench/${type}${query({ date, lang: currentLanguage() })}`,
    ),
  resource: (name: ResourceName, params: Record<string, string | number | undefined> = {}) =>
    request<PageData<Record<string, unknown>>>(`/admin/${name}${query(params)}`),
  resourceDetail: (name: ResourceName, id: number) =>
    request<Record<string, unknown>>(`/admin/${name}/${id}`),
  createResource: (name: ResourceName, payload: Record<string, unknown>) =>
    request<Record<string, unknown>>(`/admin/${name}`, {
      method: 'POST',
      body: JSON.stringify(payload),
    }),
  updateResource: (name: ResourceName, id: number, payload: Record<string, unknown>) =>
    request<Record<string, unknown>>(`/admin/${name}/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    }),
  disableResource: (name: ResourceName, id: number) =>
    request<null>(`/admin/${name}/${id}`, { method: 'DELETE' }),
  updateSessionProducts: (sessionId: number, items: Array<Record<string, unknown>>) =>
    request<Record<string, unknown>>(`/admin/sessions/${sessionId}/products`, {
      method: 'PUT',
      body: JSON.stringify({ items }),
    }),
}
