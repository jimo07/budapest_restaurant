<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { api, auth } from './api'
import { language, setLanguage, tr, useDomI18n, type Language } from './i18n'
import type {
  AdminAccount,
  AdminUser,
  Dashboard,
  OperationLog,
  Order,
  PageData,
  ResourceName,
} from './types'

type View =
  | 'dashboard'
  | 'orders'
  | 'kitchen'
  | 'delivery'
  | 'takeaway'
  | 'dine-in'
  | 'settings'
  | 'users'
  | 'logs'
  | ResourceName
const loggedIn = ref(auth.hasToken())
const user = ref<AdminUser | null>(
  JSON.parse(localStorage.getItem('budapest-admin-user') || 'null'),
)
const view = ref<View>(defaultView(user.value?.role_code))
const sidebarOpen = ref(false)
const loading = ref(false)
const error = ref('')
const toast = ref('')
const notificationEnabled = ref(localStorage.getItem('budapest-admin-notifications') === '1' && typeof Notification !== 'undefined' && Notification.permission === 'granted')
function localSqlTime() { const now = new Date(); return new Date(now.getTime() - now.getTimezoneOffset() * 60_000).toISOString().slice(0, 19).replace('T', ' ') }
let notificationSince = localSqlTime()
const date = ref(new Date().toISOString().slice(0, 10))
const loginForm = reactive({ username: 'admin', password: '' })
const dashboard = ref<Dashboard | null>(null)
const orders = ref<PageData<Order> | null>(null)
const selectedOrder = ref<Order | null>(null)
const orderFilters = reactive({
  status: '',
  fulfillment_type: '',
  meal_type: '',
  keyword: '',
  page: 1,
  page_size: 20,
})
const selectedOrderIds = ref<number[]>([])
const orderSlots = ref<Array<Record<string, unknown>>>([])
const diningTables = ref<Array<Record<string, unknown>>>([])
const selectedSlotId = ref(0)
const selectedTableId = ref(0)
const printTime = ref('')
const workbenchRows = ref<Record<string, unknown>[]>([])
const kitchenOrders = ref<Record<string, unknown>[]>([])
const adminAccounts = ref<AdminAccount[]>([])
const operationLogs = ref<OperationLog[]>([])
const logKeyword = ref('')
const userFormOpen = ref(false)
const editingUserId = ref<number | null>(null)
const userForm = reactive({
  username: '',
  password: '',
  role_code: 'order_clerk',
  status: 'active',
})
const storeSettings = reactive<Record<string, string>>({
  restaurant_name: '布达佩斯餐厅',
  restaurant_subtitle: 'HUNGARIAN KITCHEN',
  restaurant_name_en: 'Budapest Restaurant',
  restaurant_name_hu: 'Budapest Étterem',
  restaurant_subtitle_en: 'HUNGARIAN KITCHEN',
  restaurant_subtitle_hu: 'MAGYAR KONYHA',
  restaurant_phone: '',
  restaurant_address: '',
  logo_url: '',
  receipt_footer: '请按履约码核对订单',
  receipt_footer_en: 'Verify the order using the fulfillment code',
  receipt_footer_hu: 'Ellenőrizze a rendelést a teljesítési kóddal',
  cancellation_rule: '餐厅开始制作前可随时取消；开始制作后请联系餐厅处理。',
  cancellation_rule_en:
    'Orders may be cancelled before preparation starts; contact the restaurant afterwards.',
  cancellation_rule_hu:
    'A rendelés az elkészítés megkezdéséig lemondható; utána lépjen kapcsolatba az étteremmel.',
  alert_pending_minutes: '10',
  alert_preparing_minutes: '30',
  alert_ready_minutes: '20',
})
useDomI18n()
function localizedSettingKey(key: string) {
  return language.value === 'zh' ? key : `${key}_${language.value}`
}
function settingValue(key: string) {
  return storeSettings[localizedSettingKey(key)] || storeSettings[key] || ''
}
watch(language, () => { if (loggedIn.value) void loadView() })
const resourceRows = ref<PageData<Record<string, unknown>> | null>(null)
interface FormField {
  key: string
  label: string
  type?: 'text' | 'number' | 'date' | 'datetime-local' | 'time' | 'textarea' | 'select' | 'checkbox'
  required?: boolean
  options?: Array<{ value: string; label: string }>
}
const editorOpen = ref(false)
const editorId = ref<number | null>(null)
type FormValue = string | number | boolean | null | undefined
const editorData = reactive<Record<string, FormValue>>({})
const editorError = ref('')
const inventoryOpen = ref(false)
const inventorySessionId = ref(0)
const inventoryItems = ref<
  Array<{
    product_id: number
    name: string
    enabled: boolean
    sale_price: string
    stock: number | null
    status: string
  }>
>([])
const productChoices = ref<
  Array<{ id: number; name: string; name_en?: string; name_hu?: string; type: string }>
>([])
const packageItems = ref<Array<{ product_id: number; quantity: number }>>([])

const navGroups = [
  {
    title: '经营',
    items: [
      { key: 'dashboard', icon: '⌁', label: '数据概览' },
      { key: 'orders', icon: '▤', label: '订单中心' },
    ],
  },
  {
    title: '作业',
    items: [
      { key: 'kitchen', icon: '♨', label: '厨房备餐' },
      { key: 'delivery', icon: '↗', label: '配送清单' },
      { key: 'takeaway', icon: '⌂', label: '自取清单' },
      { key: 'dine-in', icon: '◇', label: '堂食排位' },
    ],
  },
  {
    title: '配置',
    items: [
      { key: 'categories', icon: '◫', label: '分类管理' },
      { key: 'products', icon: '◉', label: '商品管理' },
      { key: 'sessions', icon: '◷', label: '营业餐次' },
      { key: 'time-slots', icon: '◴', label: '预约时段' },
      { key: 'delivery-zones', icon: '⌖', label: '配送区域' },
      { key: 'tables', icon: '▦', label: '桌台管理' },
    ],
  },
  {
    title: '系统',
    items: [
      { key: 'settings', icon: '⚙', label: '店铺与规则' },
      { key: 'users', icon: '♙', label: '管理员账号' },
      { key: 'logs', icon: '≡', label: '操作日志' },
    ],
  },
] as const
function defaultView(role?: string): View {
  if (role === 'kitchen') return 'kitchen'
  if (role === 'fulfillment') return 'takeaway'
  return 'dashboard'
}
const roleViews: Record<string, string[]> = {
  order_clerk: ['dashboard', 'orders', 'kitchen', 'delivery', 'takeaway', 'dine-in'],
  kitchen: ['kitchen'],
  fulfillment: ['delivery', 'takeaway', 'dine-in'],
}
const visibleNavGroups = computed(() => {
  if (user.value?.role_code === 'super_admin') return navGroups
  const allowed = roleViews[user.value?.role_code || ''] || []
  return navGroups
    .map((group) => ({ ...group, items: group.items.filter((item) => allowed.includes(item.key)) }))
    .filter((group) => group.items.length)
})
const canManageOrders = computed(() =>
  ['super_admin', 'order_clerk'].includes(user.value?.role_code || ''),
)
const titles: Record<View, string> = {
  dashboard: '数据概览',
  orders: '订单中心',
  kitchen: '厨房备餐',
  delivery: '配送清单',
  takeaway: '自取清单',
  'dine-in': '堂食排位',
  categories: '分类管理',
  products: '商品管理',
  sessions: '营业餐次',
  'time-slots': '预约时段',
  'delivery-zones': '配送区域',
  tables: '桌台管理',
  users: '管理员账号',
  logs: '操作日志',
  settings: '店铺与营业规则',
}
const roleText: Record<string, string> = {
  super_admin: '超级管理员',
  order_clerk: '订单员',
  kitchen: '厨房人员',
  fulfillment: '履约人员',
}
const statusText: Record<string, string> = {
  pending: '待确认',
  confirmed: '已确认',
  preparing: '制作中',
  ready: '已备好',
  fulfilling: '履约中',
  completed: '已完成',
  cancelled: '已取消',
}
const statusFilterOptions = [
  { value: 'pending,confirmed', label: '待处理' },
  { value: 'preparing,ready,fulfilling', label: '进行中' },
  { value: 'completed', label: '已完成' },
  { value: 'cancelled', label: '已取消' },
]
const fulfillmentText: Record<string, string> = {
  delivery: '配送',
  takeaway: '自取',
  dine_in: '堂食',
}
const nextStatus: Record<string, string> = {
  pending: 'confirmed',
  confirmed: 'preparing',
  preparing: 'ready',
  ready: 'fulfilling',
  fulfilling: 'completed',
}
const nextStatusAction: Record<string, string> = {
  pending: '确认订单',
  confirmed: '开始制作',
  preparing: '标记备好',
  ready: '开始履约',
  fulfilling: '完成订单',
}
const nextFulfillment: Record<string, string> = {
  waiting_delivery: 'delivering',
  delivering: 'delivered',
  waiting_pickup: 'picked_up',
  waiting_arrival: 'seated',
  seated: 'served',
  served: 'dine_completed',
}
const fulfillmentStatusText: Record<string, string> = {
  waiting_delivery: '待配送',
  delivering: '配送中',
  delivered: '已送达',
  waiting_pickup: '待取餐',
  picked_up: '已取餐',
  waiting_arrival: '待到店',
  seated: '已入座',
  served: '已上菜',
  dine_completed: '堂食完成',
}
const fulfillmentActionText: Record<string, string> = {
  delivering: '开始配送',
  delivered: '确认送达',
  picked_up: '确认取餐',
  seated: '安排入座',
  served: '确认上菜',
  dine_completed: '完成堂食',
}
function canAdvanceFulfillmentState(status: string, fulfillmentStatus: string) {
  const target = nextFulfillment[fulfillmentStatus]
  if (!target) return false
  if (target === 'served') return ['ready', 'fulfilling'].includes(status)
  return true
}
const resourceColumns: Record<ResourceName, Array<{ key: string; label: string }>> = {
  categories: [
    { key: 'id', label: 'ID' },
    { key: 'name', label: '分类名称' },
    { key: 'sort_order', label: '排序' },
    { key: 'status', label: '状态' },
  ],
  products: [
    { key: 'id', label: 'ID' },
    { key: 'name', label: '商品名称' },
    { key: 'type', label: '类型' },
    { key: 'base_price', label: '基础价格' },
    { key: 'status', label: '状态' },
  ],
  sessions: [
    { key: 'service_date', label: '营业日' },
    { key: 'meal_type', label: '餐次' },
    { key: 'order_start_at', label: '开始时间' },
    { key: 'cutoff_at', label: '截止时间' },
    { key: 'status', label: '状态' },
  ],
  'time-slots': [
    { key: 'id', label: 'ID' },
    { key: 'session_id', label: '餐次ID' },
    { key: 'fulfillment_type', label: '方式' },
    { key: 'start_time', label: '开始' },
    { key: 'end_time', label: '结束' },
    { key: 'capacity', label: '容量' },
  ],
  'delivery-zones': [
    { key: 'id', label: 'ID' },
    { key: 'name', label: '区域名称' },
    { key: 'delivery_fee', label: '配送费' },
    { key: 'min_order_amount', label: '起送金额' },
    { key: 'status', label: '状态' },
  ],
  tables: [
    { key: 'id', label: 'ID' },
    { key: 'table_no', label: '桌号' },
    { key: 'capacity', label: '容纳人数' },
    { key: 'status', label: '状态' },
  ],
}
const formFields: Record<ResourceName, FormField[]> = {
  categories: [
    { key: 'name', label: '分类名称', required: true },
    { key: 'name_en', label: '分类名称（English）' },
    { key: 'name_hu', label: '分类名称（Magyar）' },
    { key: 'sort_order', label: '排序', type: 'number' },
    { key: 'status', label: '状态', type: 'select', options: statusOptions() },
  ],
  products: [
    {
      key: 'type',
      label: '商品类型',
      type: 'select',
      required: true,
      options: [
        { value: 'dish', label: '单品' },
        { value: 'package', label: '套餐' },
      ],
    },
    { key: 'category_id', label: '分类 ID', type: 'number' },
    { key: 'name', label: '商品名称', required: true },
    { key: 'name_en', label: '商品名称（English）' },
    { key: 'name_hu', label: '商品名称（Magyar）' },
    { key: 'description', label: '商品描述', type: 'textarea' },
    { key: 'description_en', label: '商品描述（English）', type: 'textarea' },
    { key: 'description_hu', label: '商品描述（Magyar）', type: 'textarea' },
    { key: 'base_price', label: '基础价格', type: 'number', required: true },
    { key: 'image_url', label: '图片地址（待素材确认）' },
    { key: 'sort_order', label: '排序', type: 'number' },
    { key: 'status', label: '状态', type: 'select', options: statusOptions() },
  ],
  sessions: [
    { key: 'service_date', label: '营业日期', type: 'date', required: true },
    {
      key: 'meal_type',
      label: '餐次',
      type: 'select',
      required: true,
      options: [
        { value: 'lunch', label: '午餐' },
        { value: 'dinner', label: '晚餐' },
      ],
    },
    { key: 'order_start_at', label: '下单开始时间', type: 'datetime-local', required: true },
    { key: 'cutoff_at', label: '下单截止时间', type: 'datetime-local', required: true },
    { key: 'enabled_delivery', label: '支持配送', type: 'checkbox' },
    { key: 'enabled_takeaway', label: '支持自取', type: 'checkbox' },
    { key: 'enabled_dine_in', label: '支持堂食', type: 'checkbox' },
    {
      key: 'status',
      label: '状态',
      type: 'select',
      options: [
        { value: 'open', label: '开放' },
        { value: 'closed', label: '关闭' },
      ],
    },
  ],
  'time-slots': [
    { key: 'session_id', label: '餐次 ID', type: 'number', required: true },
    {
      key: 'fulfillment_type',
      label: '履约方式',
      type: 'select',
      required: true,
      options: [
        { value: 'delivery', label: '配送' },
        { value: 'takeaway', label: '自取' },
        { value: 'dine_in', label: '堂食' },
      ],
    },
    { key: 'start_time', label: '开始时间', type: 'time', required: true },
    { key: 'end_time', label: '结束时间', type: 'time', required: true },
    { key: 'capacity', label: '容量', type: 'number', required: true },
    { key: 'status', label: '状态', type: 'select', options: statusOptions() },
  ],
  'delivery-zones': [
    { key: 'name', label: '区域名称', required: true },
    { key: 'name_en', label: '区域名称（English）' },
    { key: 'name_hu', label: '区域名称（Magyar）' },
    { key: 'delivery_fee', label: '配送费', type: 'number', required: true },
    { key: 'min_order_amount', label: '起送金额', type: 'number', required: true },
    { key: 'status', label: '状态', type: 'select', options: statusOptions() },
  ],
  tables: [
    { key: 'table_no', label: '桌号', required: true },
    { key: 'capacity', label: '容纳人数', type: 'number', required: true },
    { key: 'status', label: '状态', type: 'select', options: statusOptions() },
  ],
}
const isResource = computed(() =>
  ['categories', 'products', 'sessions', 'time-slots', 'delivery-zones', 'tables'].includes(
    view.value,
  ),
)

async function login() {
  error.value = ''
  loading.value = true
  try {
    const data = await auth.login(loginForm.username, loginForm.password)
    user.value = data.user
    loggedIn.value = true
    view.value = defaultView(data.user.role_code)
    Object.assign(storeSettings, await api.store())
    await loadView()
  } catch (e) {
    error.value = messageOf(e)
  } finally {
    loading.value = false
  }
}
function logout() {
  auth.logout()
  loggedIn.value = false
  user.value = null
  selectedOrder.value = null
}
async function navigate(target: View) {
  view.value = target
  sidebarOpen.value = false
  selectedOrder.value = null
  await loadView()
}
async function loadView() {
  error.value = ''
  loading.value = true
  try {
    if (view.value === 'dashboard') dashboard.value = await api.dashboard(date.value)
    else if (view.value === 'orders')
      orders.value = await api.orders({ ...orderFilters, service_date: date.value })
    else if (['kitchen', 'delivery', 'takeaway', 'dine-in'].includes(view.value)) {
      workbenchRows.value = []
      const apiType = view.value === 'dine-in' ? 'dine_in' : view.value
      const [rows, kitchenQueue] = await Promise.all([
        api.workbench(apiType, date.value),
        view.value === 'kitchen'
          ? api.workbench('kitchen_orders', date.value)
          : Promise.resolve([]),
      ])
      kitchenOrders.value = kitchenQueue
      const expected = view.value === 'dine-in' ? 'dine_in' : view.value
      workbenchRows.value =
        view.value === 'kitchen' ? rows : rows.filter((row) => row.fulfillment_type === expected)
    } else if (view.value === 'users') adminAccounts.value = (await api.users()).data
    else if (view.value === 'logs') operationLogs.value = (await api.logs(logKeyword.value)).data
    else if (view.value === 'settings') Object.assign(storeSettings, await api.settings())
    else resourceRows.value = await api.resource(view.value as ResourceName)
  } catch (e) {
    error.value = messageOf(e)
    if (messageOf(e).includes('登录')) loggedIn.value = false
  } finally {
    loading.value = false
  }
}
async function saveSettings() {
  try {
    Object.assign(storeSettings, await api.updateSettings({ ...storeSettings }))
    showToast('店铺与营业规则已保存')
  } catch (e) {
    error.value = messageOf(e)
  }
}
function openUserForm(account?: AdminAccount) {
  editingUserId.value = account?.id || null
  Object.assign(userForm, {
    username: account?.username || '',
    password: '',
    role_code: account?.role_code || 'order_clerk',
    status: account?.status || 'active',
  })
  userFormOpen.value = true
}
async function saveUser() {
  try {
    if (!editingUserId.value) await api.createUser({ ...userForm })
    else
      await api.updateUser(editingUserId.value, {
        role_code: userForm.role_code,
        status: userForm.status,
        password: userForm.password,
      })
    userFormOpen.value = false
    showToast(editingUserId.value ? '管理员已更新' : '管理员已创建')
    adminAccounts.value = (await api.users()).data
  } catch (e) {
    error.value = messageOf(e)
  }
}
async function openOrder(id: number) {
  loading.value = true
  try {
    selectedOrder.value = await api.order(id)
    selectedSlotId.value = selectedOrder.value.time_slot_id
    const [slots, tables] = await Promise.all([
      api.resource('time-slots', { session_id: selectedOrder.value.session_id, page_size: 100 }),
      selectedOrder.value.fulfillment_type === 'dine_in'
        ? api.resource('tables', { status: 'active', page_size: 100 })
        : Promise.resolve({ data: [] }),
    ])
    orderSlots.value = slots.data.filter(
      (slot) => slot.fulfillment_type === selectedOrder.value?.fulfillment_type,
    )
    diningTables.value = tables.data
    selectedTableId.value = Number(selectedOrder.value.table_id || 0)
  } catch (e) {
    error.value = messageOf(e)
  } finally {
    loading.value = false
  }
}
async function advanceOrder() {
  if (!selectedOrder.value) return
  const target = nextStatus[selectedOrder.value.status]
  if (!target) return
  try {
    const orderId = selectedOrder.value.id
    await api.updateStatus(orderId, target)
    selectedOrder.value = await api.order(orderId)
    showToast(`订单已更新为${statusText[target]}`)
    await loadOrdersQuietly()
    if (target === 'confirmed') await printOrder()
  } catch (e) {
    error.value = messageOf(e)
  }
}
async function cancelAdminOrder() {
  if (!selectedOrder.value || !confirm('确定取消此订单？库存和容量将返还。')) return
  const reason = prompt('请输入取消原因')?.trim()
  if (!reason) return
  try {
    const orderId = selectedOrder.value.id
    await api.updateStatus(orderId, 'cancelled', reason)
    selectedOrder.value = await api.order(orderId)
    showToast('订单已取消')
    await loadOrdersQuietly()
  } catch (e) {
    error.value = messageOf(e)
  }
}
async function loadOrdersQuietly() {
  orders.value = await api.orders({ ...orderFilters, service_date: date.value })
}
async function exportOrders() {
  try {
    await api.exportOrders({ ...orderFilters, service_date: date.value })
    showToast('订单表已导出，可使用 Excel 打开')
  } catch (e) {
    error.value = messageOf(e)
  }
}
async function changePage(page: number) {
  orderFilters.page = page
  selectedOrderIds.value = []
  await loadView()
}
function toggleAllOrders(event: Event) {
  selectedOrderIds.value = (event.target as HTMLInputElement).checked
    ? orders.value?.data.map((item) => item.id) || []
    : []
}
async function batchAdvance() {
  if (!selectedOrderIds.value.length) return
  const statuses = new Set(
    orders.value?.data
      .filter((item) => selectedOrderIds.value.includes(item.id))
      .map((item) => item.status),
  )
  if (statuses.size !== 1) {
    error.value = '批量更新要求所选订单当前状态一致'
    return
  }
  const current = [...statuses][0] || ''
  const target = nextStatus[current]
  if (!target) {
    error.value = '所选订单没有可用的下一状态'
    return
  }
  if (target === 'confirmed') {
    error.value = '待确认订单请逐单确认，确认后系统会自动打印小票'
    return
  }
  const result = await api.batchStatus(selectedOrderIds.value, target)
  const failed = result.filter((item) => !item.success)
  showToast(
    failed.length
      ? `${result.length - failed.length} 张成功，${failed.length} 张失败`
      : `${result.length} 张订单已更新`,
  )
  selectedOrderIds.value = []
  await loadOrdersQuietly()
}
async function quickAdvanceOrder(item: Order) {
  const target = nextStatus[item.status]
  if (!target) return
  try {
    await api.updateStatus(item.id, target)
    showToast(`${item.order_no} → ${statusText[target]}`)
    await loadOrdersQuietly()
    if (target === 'confirmed') {
      await openOrder(item.id)
      await printOrder()
    }
  } catch (e) {
    error.value = messageOf(e)
  }
}
async function quickAdvanceFulfillment(row: Record<string, unknown>) {
  const current = String(row.fulfillment_status)
  const target = nextFulfillment[current]
  if (!target) return
  if (target === 'seated') {
    await openOrder(Number(row.id))
    return
  }
  try {
    await api.updateFulfillment(Number(row.id), target)
    showToast(`${row.order_no} → ${fulfillmentStatusText[target]}`)
    await loadView()
  } catch (e) {
    error.value = messageOf(e)
  }
}
async function quickAdvanceKitchen(row: Record<string, unknown>) {
  const target = String(row.status) === 'confirmed' ? 'preparing' : 'ready'
  try {
    await api.updateStatus(Number(row.id), target)
    showToast(
      `${row.fulfillment_code || row.order_no} → ${target === 'preparing' ? '开始制作' : '已备好'}`,
    )
    await loadView()
  } catch (e) {
    error.value = messageOf(e)
  }
}
async function updatePayment(status: string) {
  if (!selectedOrder.value) return
  const prompt =
    status === 'paid'
      ? `确认已收到 ${money(selectedOrder.value.payable_amount)} 吗？`
      : `确认将 ${money(selectedOrder.value.payable_amount)} 登记为已退款吗？`
  if (!confirm(prompt)) return
  try {
    const orderId = selectedOrder.value.id
    await api.updatePayment(orderId, status)
    selectedOrder.value = await api.order(orderId)
    showToast(status === 'paid' ? '已确认收款' : '已登记退款')
    await loadOrdersQuietly()
  } catch (e) {
    error.value = messageOf(e)
  }
}
async function advanceFulfillment() {
  if (!selectedOrder.value) return
  const target = nextFulfillment[selectedOrder.value.fulfillment_status]
  if (!target) return
  if (target === 'seated' && !selectedTableId.value) {
    error.value = '请先选择堂食桌台'
    return
  }
  try {
    const orderId = selectedOrder.value.id
    await api.updateFulfillment(
      orderId,
      target,
      selectedTableId.value || undefined,
    )
    selectedOrder.value = await api.order(orderId)
    showToast(`履约状态已更新为${fulfillmentStatusText[target]}`)
    await loadOrdersQuietly()
  } catch (e) {
    error.value = messageOf(e)
  }
}
async function rescheduleOrder() {
  if (!selectedOrder.value || selectedSlotId.value === selectedOrder.value.time_slot_id) return
  try {
    const orderId = selectedOrder.value.id
    await api.reschedule(orderId, selectedSlotId.value)
    selectedOrder.value = await api.order(orderId)
    showToast('预约时段已修改')
    await loadOrdersQuietly()
  } catch (e) {
    error.value = messageOf(e)
  }
}
async function printOrder() {
  printTime.value = new Date().toLocaleString('zh-CN', { hour12: false })
  await nextTick()
  window.print()
}
async function printOrderFromList(item: Order) {
  await openOrder(item.id)
  if (selectedOrder.value?.id === item.id) await printOrder()
}
function showToast(value: string) {
  toast.value = value
  setTimeout(() => (toast.value = ''), 2200)
}
async function toggleNotifications() {
  if (notificationEnabled.value) {
    notificationEnabled.value = false
    localStorage.removeItem('budapest-admin-notifications')
    showToast('消息提醒已关闭')
    return
  }
  if (typeof Notification === 'undefined') { error.value = '当前浏览器不支持系统通知'; return }
  const permission = await Notification.requestPermission()
  if (permission !== 'granted') { error.value = '请在浏览器设置中允许通知'; return }
  notificationEnabled.value = true
  localStorage.setItem('budapest-admin-notifications', '1')
  showToast('消息提醒已开启')
}
function playNoticeSound() {
  const ExtendedWindow = window as typeof window & { webkitAudioContext?: typeof AudioContext }
  const AudioContextClass = window.AudioContext || ExtendedWindow.webkitAudioContext
  if (!AudioContextClass) return
  const context = new AudioContextClass()
  const oscillator = context.createOscillator()
  const gain = context.createGain()
  oscillator.frequency.value = 880
  gain.gain.value = 0.06
  oscillator.connect(gain); gain.connect(context.destination); oscillator.start(); oscillator.stop(context.currentTime + 0.16)
}
async function pollNotifications() {
  if (!notificationEnabled.value || !loggedIn.value) return
  const checkedAt = localSqlTime()
  try {
    const events = await api.notifications(notificationSince)
    notificationSince = checkedAt
    if (!events.length) return
    playNoticeSound()
    for (const event of events.slice(-3)) {
      const status = String(event.status)
      const title = status === 'pending' ? '有新订单待确认' : status === 'confirmed' ? '有订单等待制作' : status === 'ready' ? '餐点已备好，等待履约' : '订单状态已更新'
      new Notification(tr(title), { body: tr(`${event.fulfillment_code || event.order_no} · ${fulfillmentText[String(event.fulfillment_type)] || ''}`) })
    }
  } catch { /* 下一轮自动重试 */ }
}
function messageOf(value: unknown) {
  return value instanceof Error ? value.message : '发生未知错误'
}
function money(value: string | number | undefined) {
  return new Intl.NumberFormat('hu-HU', {
    style: 'currency',
    currency: 'HUF',
    maximumFractionDigits: 0,
  }).format(Number(value || 0))
}
function percent(used: number, capacity: number) {
  return capacity ? Math.min(100, Math.round((used / capacity) * 100)) : 0
}
function displayValue(value: unknown) {
  if (value === null || value === undefined || value === '') return '—'
  if (value === 'active' || value === 'open') return '启用'
  if (value === 'inactive' || value === 'closed') return '停用'
  return String(value)
}
function resourceDisplay(row: Record<string, unknown>, key: string) {
  if (key === 'name' && language.value !== 'zh') return displayValue(row[`name_${language.value}`] || row.name)
  return displayValue(row[key])
}
function fulfillmentCodeLabel(value: string) {
  return { delivery: '配送码', takeaway: '取餐码', dine_in: '就餐码' }[value] || '履约码'
}
function displayStatusText(status: string) {
  if (['pending', 'confirmed'].includes(status)) return '待处理'
  if (['preparing', 'ready', 'fulfilling', 'in_progress'].includes(status)) return '进行中'
  return statusText[status] || status
}
const groupedStatusLogs = computed(() => {
  const result: Array<{ id: number; label: string; created_at: string }> = []
  for (const log of selectedOrder.value?.status_logs || []) {
    const label = displayStatusText(log.to_status)
    if (result.at(-1)?.label === label) continue
    result.push({ id: log.id, label, created_at: log.created_at })
  }
  return result
})
function setEditorField(key: string, event: Event) {
  editorData[key] = (
    event.target as HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement
  ).value
}
function statusOptions() {
  return [
    { value: 'active', label: '启用' },
    { value: 'inactive', label: '停用' },
  ]
}
function defaultsFor(name: ResourceName): Record<string, unknown> {
  const common = { status: name === 'sessions' ? 'open' : 'active' }
  if (name === 'categories') return { ...common, sort_order: 0 }
  if (name === 'products') return { ...common, type: 'dish', base_price: 0, sort_order: 0 }
  if (name === 'sessions')
    return {
      ...common,
      meal_type: 'lunch',
      enabled_delivery: true,
      enabled_takeaway: true,
      enabled_dine_in: true,
    }
  if (name === 'time-slots') return { ...common, fulfillment_type: 'takeaway', capacity: 20 }
  if (name === 'delivery-zones') return { ...common, delivery_fee: 0, min_order_amount: 0 }
  return { ...common, capacity: 2 }
}
function normalizeForInput(data: Record<string, unknown>) {
  for (const [key, value] of Object.entries(data))
    if (value === null || ['string', 'number', 'boolean', 'undefined'].includes(typeof value))
      editorData[key] =
        ['order_start_at', 'cutoff_at'].includes(key) && typeof value === 'string'
          ? value.replace(' ', 'T').slice(0, 16)
          : (value as FormValue)
}
async function loadProductChoices() {
  const result = await api.resource('products', { page_size: 100 })
  productChoices.value = result.data.map((item) => ({
    id: Number(item.id),
    name: String(item.name),
    name_en: item.name_en ? String(item.name_en) : undefined,
    name_hu: item.name_hu ? String(item.name_hu) : undefined,
    type: String(item.type),
  }))
}
function localizedProductName(product: { name: string; name_en?: string; name_hu?: string }) {
  if (language.value === 'en') return product.name_en || product.name
  if (language.value === 'hu') return product.name_hu || product.name
  return product.name
}
function editorFieldLabel(field: FormField) {
  const labels: Record<string, string> = {
    name: view.value === 'categories' ? '分类名称（中文）' : view.value === 'delivery-zones' ? '区域名称（中文）' : '商品名称（中文）',
    name_en: view.value === 'categories' ? '分类名称（English）' : view.value === 'delivery-zones' ? '区域名称（English）' : '商品名称（English）',
    name_hu: view.value === 'categories' ? '分类名称（Magyar）' : view.value === 'delivery-zones' ? '区域名称（Magyar）' : '商品名称（Magyar）',
    description: '商品描述（中文）',
    description_en: '商品描述（English）',
    description_hu: '商品描述（Magyar）',
    base_price: '基础价格（HUF）',
  }
  return tr(labels[field.key] || field.label)
}
async function openCreate() {
  editorId.value = null
  Object.keys(editorData).forEach((key) => delete editorData[key])
  normalizeForInput(defaultsFor(view.value as ResourceName))
  packageItems.value = []
  editorError.value = ''
  if (view.value === 'products') await loadProductChoices()
  editorOpen.value = true
}
async function openEdit(row: Record<string, unknown>) {
  editorId.value = Number(row.id)
  Object.keys(editorData).forEach((key) => delete editorData[key])
  editorError.value = ''
  try {
    const detail = await api.resourceDetail(view.value as ResourceName, editorId.value)
    normalizeForInput(detail)
    packageItems.value = ((detail.package_items || []) as Array<Record<string, unknown>>).map(
      (item) => ({ product_id: Number(item.product_id), quantity: Number(item.quantity) }),
    )
    if (view.value === 'products') await loadProductChoices()
    editorOpen.value = true
  } catch (e) {
    error.value = messageOf(e)
  }
}
async function saveResource() {
  const name = view.value as ResourceName
  editorError.value = ''
  for (const field of formFields[name])
    if (
      field.required &&
      (editorData[field.key] === '' ||
        editorData[field.key] === undefined ||
        editorData[field.key] === null)
    ) {
      editorError.value = `请填写${field.label}`
      return
    }
  const payload: Record<string, unknown> = { ...editorData }
  delete payload.id
  delete payload.created_at
  delete payload.updated_at
  delete payload.used_capacity
  delete payload.sold_qty
  delete payload.products
  delete payload.package_items
  if (name === 'products' && payload.type === 'package')
    payload.package_items = packageItems.value.filter(
      (item) => item.product_id > 0 && item.quantity > 0,
    )
  for (const key of ['order_start_at', 'cutoff_at'])
    if (typeof payload[key] === 'string') payload[key] = payload[key].replace('T', ' ')
  loading.value = true
  try {
    if (editorId.value) await api.updateResource(name, editorId.value, payload)
    else await api.createResource(name, payload)
    editorOpen.value = false
    showToast(editorId.value ? '保存成功' : '创建成功')
    await loadView()
  } catch (e) {
    editorError.value = messageOf(e)
  } finally {
    loading.value = false
  }
}
async function disableResource(row: Record<string, unknown>) {
  if (!confirm(`确定停用“${row.name || row.table_no || row.id}”吗？`)) return
  try {
    await api.disableResource(view.value as ResourceName, Number(row.id))
    showToast('已停用')
    await loadView()
  } catch (e) {
    error.value = messageOf(e)
  }
}
async function openInventory(row: Record<string, unknown>) {
  loading.value = true
  try {
    const [session, products] = await Promise.all([
      api.resourceDetail('sessions', Number(row.id)),
      api.resource('products', { page_size: 100 }),
    ])
    const assigned = new Map(
      ((session.products || []) as Array<Record<string, unknown>>).map((item) => [
        Number(item.product_id),
        item,
      ]),
    )
    inventorySessionId.value = Number(row.id)
    inventoryItems.value = products.data.map((product) => {
      const current = assigned.get(Number(product.id))
      return {
        product_id: Number(product.id),
        name: localizedProductName({
          name: String(product.name),
          name_en: product.name_en ? String(product.name_en) : undefined,
          name_hu: product.name_hu ? String(product.name_hu) : undefined,
        }),
        enabled: current?.status === 'active',
        sale_price: String(current?.sale_price ?? product.base_price ?? '0.00'),
        stock: current ? (current.stock === null ? null : Number(current.stock)) : null,
        status: String(current?.status || 'inactive'),
      }
    })
    inventoryOpen.value = true
  } catch (e) {
    error.value = messageOf(e)
  } finally {
    loading.value = false
  }
}
async function saveInventory() {
  loading.value = true
  try {
    await api.updateSessionProducts(
      inventorySessionId.value,
      inventoryItems.value.map((item) => ({
        product_id: item.product_id,
        sale_price: item.sale_price,
        stock: item.stock,
        status: item.enabled ? 'active' : 'inactive',
      })),
    )
    inventoryOpen.value = false
    showToast('餐次菜单与库存已保存')
    await loadView()
  } catch (e) {
    error.value = messageOf(e)
  } finally {
    loading.value = false
  }
}
function addPackageItem() {
  packageItems.value.push({ product_id: 0, quantity: 1 })
}
function removePackageItem(index: number) {
  packageItems.value.splice(index, 1)
}

let refreshTimer: number | undefined
async function refreshLiveData() {
  if (
    !loggedIn.value ||
    document.hidden ||
    loading.value ||
    editorOpen.value ||
    inventoryOpen.value
  )
    return
  try {
    await pollNotifications()
    if (view.value === 'dashboard') dashboard.value = await api.dashboard(date.value)
    else if (view.value === 'orders') {
      await loadOrdersQuietly()
      if (selectedOrder.value) selectedOrder.value = await api.order(selectedOrder.value.id)
    } else if (['kitchen', 'delivery', 'takeaway', 'dine-in'].includes(view.value)) {
      const apiType = view.value === 'dine-in' ? 'dine_in' : view.value
      const [rows, kitchenQueue] = await Promise.all([
        api.workbench(apiType, date.value),
        view.value === 'kitchen'
          ? api.workbench('kitchen_orders', date.value)
          : Promise.resolve([]),
      ])
      kitchenOrders.value = kitchenQueue
      const expected = view.value === 'dine-in' ? 'dine_in' : view.value
      workbenchRows.value =
        view.value === 'kitchen' ? rows : rows.filter((row) => row.fulfillment_type === expected)
    }
  } catch {
    // 定时刷新失败时保留当前画面，下一轮自动重试。
  }
}

onMounted(() => {
  void api.store().then((settings) => Object.assign(storeSettings, settings))
  if (loggedIn.value) void loadView()
  refreshTimer = window.setInterval(() => void refreshLiveData(), 10_000)
})
onUnmounted(() => window.clearInterval(refreshTimer))
</script>

<template>
  <div v-if="!loggedIn" class="login-page">
    <select class="admin-language-switch login-language-switch" :value="language" aria-label="切换语言" @change="setLanguage(($event.target as HTMLSelectElement).value as Language)"><option value="zh">🇨🇳 中文</option><option value="en">🇬🇧 English</option><option value="hu">🇭🇺 Magyar</option></select>
    <section class="login-brand">
      <div class="brand-badge">B</div>
      <p>BUDAPEST RESTAURANT</p>
      <h1>把每一餐，<br />安排得井井有条。</h1>
      <span>菜单、订单、备餐与履约，一处完成。</span>
    </section>
    <section class="login-card">
      <p class="eyebrow">MANAGEMENT CONSOLE</p>
      <h2>欢迎回来</h2>
      <p>登录餐厅管理后台</p>
      <form @submit.prevent="login">
        <label
          >用户名<input
            v-model="loginForm.username"
            autocomplete="username"
            placeholder="请输入用户名" /></label
        ><label
          >密码<input
            v-model="loginForm.password"
            type="password"
            autocomplete="current-password"
            placeholder="请输入密码"
        /></label>
        <div v-if="error" class="form-error">{{ error }}</div>
        <button class="primary" :disabled="loading">
          {{ loading ? '正在登录…' : '登录后台' }} <span>→</span>
        </button>
      </form>
      <small>本地演示账号：admin / Admin@123456</small>
    </section>
  </div>

  <div v-else class="admin-shell">
    <aside :class="{ open: sidebarOpen }">
      <div class="side-brand">
        <span>B</span>
        <div><strong>Budapest</strong><small>Restaurant OS</small></div>
      </div>
      <nav>
        <section v-for="group in visibleNavGroups" :key="group.title">
          <p>{{ group.title }}</p>
          <button
            v-for="item in group.items"
            :key="item.key"
            :class="{ active: view === item.key }"
            @click="navigate(item.key)"
          >
            <i>{{ item.icon }}</i
            >{{ item.label }}
          </button>
        </section>
      </nav>
      <div class="user-card">
        <div>{{ user?.username.slice(0, 1).toUpperCase() }}</div>
        <span
          ><strong>{{ user?.username }}</strong
          ><small>{{ user?.role_code }}</small></span
        ><button title="退出登录" @click="logout">↪</button>
      </div>
    </aside>
    <div v-if="sidebarOpen" class="side-mask" @click="sidebarOpen = false"></div>
    <main class="workspace">
      <header>
        <button class="menu-button" @click="sidebarOpen = true">☰</button>
        <div>
          <p class="eyebrow">BUDAPEST · OPERATIONS</p>
          <h1>{{ titles[view] }}</h1>
        </div>
        <label class="date-picker"
          >营业日<input v-model="date" type="date" @change="loadView"
        /></label>
        <select
          class="admin-language-switch"
          :value="language"
          aria-label="切换语言"
          @change="setLanguage(($event.target as HTMLSelectElement).value as Language)"
        >
          <option value="zh">🇨🇳 中文</option>
          <option value="en">🇬🇧 English</option>
          <option value="hu">🇭🇺 Magyar</option>
        </select>
        <button :class="['notification-toggle',{active:notificationEnabled}]" :title="notificationEnabled ? '关闭消息提醒' : '开启消息提醒'" @click="toggleNotifications">{{ notificationEnabled ? '🔔' : '🔕' }}</button>
      </header>
      <div v-if="toast" class="toast">{{ toast }}</div>
      <div v-if="error" class="alert">{{ error }}<button @click="error = ''">×</button></div>
      <div v-if="loading" class="loading">正在加载数据…</div>

      <template v-if="view === 'dashboard' && dashboard">
        <section class="metrics">
          <article>
            <span>有效订单</span><strong>{{ dashboard.metrics.order_count }}</strong
            ><small>不含取消订单</small>
          </article>
          <article>
            <span>预约人数</span><strong>{{ dashboard.metrics.people_count }}</strong
            ><small>堂食按人数统计</small>
          </article>
          <article>
            <span>应收金额</span><strong>{{ money(dashboard.metrics.payable_amount) }}</strong
            ><small>含配送费</small>
          </article>
          <article class="accent">
            <span>实收金额</span><strong>{{ money(dashboard.metrics.paid_amount) }}</strong
            ><small>仅已支付订单</small>
          </article>
        </section>
        <section v-if="dashboard.alerts?.length" class="exception-panel card">
          <div class="card-head">
            <div>
              <p class="eyebrow">ATTENTION REQUIRED</p>
              <h2>需要处理</h2>
            </div>
            <span>{{ dashboard.alerts.length }} 项提醒</span>
          </div>
          <div class="exception-grid">
            <button
              v-for="alert in dashboard.alerts"
              :key="alert.id"
              :class="['exception-item', alert.level]"
              @click="openOrder(alert.id)"
            >
              <i>!</i>
              <div>
                <strong>{{ alert.reason }}</strong
                ><span
                  >{{ fulfillmentCodeLabel(alert.fulfillment_type) }}
                  {{ alert.fulfillment_code || '—' }} · {{ alert.customer_name }}</span
                ><small
                  >{{ displayStatusText(alert.status) }} · 最后更新 {{ alert.updated_at }}</small
                >
              </div>
              <b>{{ money(alert.payable_amount) }} →</b>
            </button>
          </div>
        </section>
        <section class="dashboard-grid">
          <article class="card">
            <div class="card-head">
              <h2>订单状态</h2>
              <span>今日进度</span>
            </div>
            <div class="status-list">
              <div v-for="(count, key) in dashboard.status_distribution" :key="key">
                <span><i :class="`dot ${key}`"></i>{{ displayStatusText(String(key)) }}</span
                ><strong>{{ count }}</strong>
              </div>
              <div v-if="!Object.keys(dashboard.status_distribution).length" class="empty">
                暂无订单
              </div>
            </div>
          </article>
          <article class="card">
            <div class="card-head">
              <h2>履约分布</h2>
              <span>方式占比</span>
            </div>
            <div class="fulfillment-chart">
              <div v-for="(count, key) in dashboard.fulfillment_distribution" :key="key">
                <strong>{{ count }}</strong
                ><span>{{ fulfillmentText[key] || key }}</span>
              </div>
              <div v-if="!Object.keys(dashboard.fulfillment_distribution).length" class="empty">
                暂无数据
              </div>
            </div>
          </article>
        </section>
        <section class="dashboard-grid lower">
          <article class="card">
            <div class="card-head">
              <h2>热销商品</h2>
              <span>TOP 10</span>
            </div>
            <table>
              <thead>
                <tr>
                  <th>商品</th>
                  <th>销量</th>
                  <th>金额</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in dashboard.product_ranking" :key="item.product_id">
                  <td>{{ item.product_name }}</td>
                  <td>{{ item.quantity }}</td>
                  <td>{{ money(item.amount) }}</td>
                </tr>
              </tbody>
            </table>
          </article>
          <article class="card">
            <div class="card-head">
              <h2>时段容量</h2>
              <span>实时占用</span>
            </div>
            <div v-for="slot in dashboard.slot_capacity" :key="slot.id" class="capacity">
              <div>
                <span
                  >{{ slot.start_time.slice(0, 5) }} ·
                  {{ fulfillmentText[slot.fulfillment_type] }}</span
                ><b>{{ slot.used_capacity }}/{{ slot.capacity }}</b>
              </div>
              <progress :value="percent(slot.used_capacity, slot.capacity)" max="100"></progress>
            </div>
          </article>
        </section>
      </template>

      <template v-else-if="view === 'orders'">
        <section class="filter-bar">
          <input v-model="orderFilters.keyword" placeholder="订单号、履约码、姓名或手机号" /><select
            v-model="orderFilters.status"
          >
            <option value="">全部状态</option>
            <option v-for="item in statusFilterOptions" :key="item.value" :value="item.value">
              {{ item.label }}
            </option></select
          ><select v-model="orderFilters.fulfillment_type">
            <option value="">全部方式</option>
            <option value="delivery">配送</option>
            <option value="takeaway">自取</option>
            <option value="dine_in">堂食</option></select
          ><button class="primary small" @click="loadView">筛选</button>
          <button class="secondary small" @click="exportOrders">导出 Excel 表</button>
        </section>
        <section v-if="selectedOrderIds.length" class="batch-bar">
          <span>已选 {{ selectedOrderIds.length }} 张订单</span
          ><button class="primary small" @click="batchAdvance">批量推进下一状态</button
          ><button @click="selectedOrderIds = []">取消选择</button>
        </section>
        <section class="card table-card">
          <div class="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>
                    <input
                      type="checkbox"
                      :checked="
                        Boolean(orders?.data.length) &&
                        selectedOrderIds.length === orders?.data.length
                      "
                      @change="toggleAllOrders"
                    />
                  </th>
                  <th>订单 / 履约码</th>
                  <th>顾客</th>
                  <th>餐次/方式</th>
                  <th>金额</th>
                  <th>状态</th>
                  <th>下单时间</th>
                  <th>快捷操作</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in orders?.data" :key="item.id" @click="openOrder(item.id)">
                  <td @click.stop>
                    <input v-model="selectedOrderIds" type="checkbox" :value="item.id" />
                  </td>
                  <td>
                    <strong>{{ item.order_no }}</strong
                    ><small v-if="item.fulfillment_code" class="list-code"
                      >{{ fulfillmentCodeLabel(item.fulfillment_type) }}
                      {{ item.fulfillment_code }}</small
                    >
                  </td>
                  <td>
                    {{ item.customer_name }}<small>{{ item.customer_phone }}</small>
                  </td>
                  <td>
                    {{ item.meal_type === 'lunch' ? '午餐' : '晚餐' }} ·
                    {{ fulfillmentText[item.fulfillment_type] }}
                  </td>
                  <td>{{ money(item.payable_amount) }}</td>
                  <td>
                    <span :class="`status-tag ${item.status}`">{{
                      displayStatusText(item.status)
                    }}</span>
                  </td>
                  <td>{{ item.created_at }}</td>
                  <td @click.stop>
                    <button
                      v-if="nextStatus[item.status]"
                      class="quick-button"
                      @click="quickAdvanceOrder(item)"
                    >
                      {{ nextStatusAction[item.status] }}</button
                    ><button class="print-list-button" @click="printOrderFromList(item)">
                      ▣ 打印
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="!orders?.data.length" class="empty">没有符合条件的订单</div>
          <footer v-if="orders && orders.last_page > 1" class="pagination">
            <button
              :disabled="orders.current_page <= 1"
              @click="changePage(orders.current_page - 1)"
            >
              上一页</button
            ><span
              >{{ orders.current_page }} / {{ orders.last_page }} · 共 {{ orders.total }} 张</span
            ><button
              :disabled="orders.current_page >= orders.last_page"
              @click="changePage(orders.current_page + 1)"
            >
              下一页
            </button>
          </footer>
        </section>
      </template>

      <template v-else-if="['kitchen', 'delivery', 'takeaway', 'dine-in'].includes(view)">
        <section v-if="view === 'kitchen'" class="kitchen-queue">
          <div class="section-title">
            <div>
              <p class="eyebrow">KITCHEN QUEUE</p>
              <h2>逐单制作</h2>
            </div>
            <span>{{ kitchenOrders.length }} 张待处理</span>
          </div>
          <div class="kitchen-order-grid">
            <article
              v-for="row in kitchenOrders"
              :key="String(row.id)"
              :class="['card', 'kitchen-order', String(row.status)]"
            >
              <div class="kitchen-code">
                <span>{{ fulfillmentCodeLabel(String(row.fulfillment_type)) }}</span
                ><strong>{{ row.fulfillment_code }}</strong>
              </div>
              <div>
                <h3>{{ row.items_text }}</h3>
                <p>
                  {{ row.start_time }}–{{ row.end_time }} ·
                  {{ fulfillmentText[String(row.fulfillment_type)] }}
                </p>
                <small v-if="row.remark">备注：{{ row.remark }}</small>
              </div>
              <button class="primary" @click="quickAdvanceKitchen(row)">
                {{ String(row.status) === 'confirmed' ? '开始制作' : '标记备好' }}
              </button>
            </article>
          </div>
          <div v-if="!kitchenOrders.length" class="empty card">当前没有等待制作的订单</div>
          <div class="section-title summary-title">
            <div>
              <p class="eyebrow">PREP SUMMARY</p>
              <h2>菜品汇总</h2>
            </div>
          </div>
        </section>
        <section class="workbench-grid">
          <article v-for="(row, index) in workbenchRows" :key="index" class="card work-card">
            <span class="work-index">{{ String(index + 1).padStart(2, '0') }}</span>
            <div>
              <h3>
                {{
                  row.product_name ||
                  `${fulfillmentCodeLabel(String(row.fulfillment_type))} ${row.fulfillment_code}`
                }}
              </h3>
              <p v-if="row.order_no">订单 {{ row.order_no }}</p>
              <p v-if="row.customer_name">
                {{ row.customer_name }} · {{ row.customer_phone_masked }}
              </p>
              <p v-if="row.start_time">{{ row.start_time }}–{{ row.end_time }}</p>
              <p v-if="row.table_no">桌号 {{ row.table_no }}</p>
              <p v-if="row.address">{{ row.address }}</p>
            </div>
            <div class="work-actions">
              <strong>{{
                row.quantity
                  ? `${row.quantity} 份`
                  : fulfillmentStatusText[String(row.fulfillment_status)]
              }}</strong
              ><button
                v-if="
                  view !== 'kitchen' &&
                  canAdvanceFulfillmentState(String(row.status), String(row.fulfillment_status))
                "
                class="quick-button"
                @click="quickAdvanceFulfillment(row)"
              >
                {{ fulfillmentActionText[nextFulfillment[String(row.fulfillment_status)]!] }}
              </button>
              <small
                v-if="
                  String(row.fulfillment_status) === 'seated' &&
                  !['ready', 'fulfilling'].includes(String(row.status))
                "
                >等待餐点备好</small
              >
            </div>
          </article>
          <div v-if="!workbenchRows.length" class="empty card">当前没有待处理任务</div>
        </section>
      </template>

      <template v-else-if="view === 'settings'">
        <form class="settings-form" @submit.prevent="saveSettings">
          <section class="card">
            <div class="card-head">
              <h2>店铺信息</h2>
              <span>顾客端与小票共用</span>
            </div>
            <div class="settings-grid">
              <label>餐厅名称<input v-model="storeSettings[localizedSettingKey('restaurant_name')]" required /></label
              ><label>副标题<input v-model="storeSettings[localizedSettingKey('restaurant_subtitle')]" /></label
              ><label>联系电话<input v-model="storeSettings.restaurant_phone" /></label
              ><label>餐厅地址<input v-model="storeSettings[localizedSettingKey('restaurant_address')]" /></label
              ><label class="wide"
                >Logo 图片地址<input
                  v-model="storeSettings.logo_url"
                  placeholder="待品牌素材确定后填写"
              /></label>
            </div>
          </section>
          <section class="card">
            <div class="card-head"><h2>订单规则与小票</h2></div>
            <div class="settings-grid">
              <label class="wide"
                >顾客取消规则<textarea v-model="storeSettings[localizedSettingKey('cancellation_rule')]"></textarea></label
              ><label class="wide"
                >小票底部提示<textarea v-model="storeSettings[localizedSettingKey('receipt_footer')]"></textarea>
              </label>
            </div>
          </section>
          <section class="card">
            <div class="card-head">
              <h2>异常提醒阈值</h2>
              <span>单位：分钟</span>
            </div>
            <div class="settings-grid three">
              <label
                >未确认提醒<input
                  v-model="storeSettings.alert_pending_minutes"
                  type="number"
                  min="1"
                  max="1440" /></label
              ><label
                >制作超时提醒<input
                  v-model="storeSettings.alert_preparing_minutes"
                  type="number"
                  min="1"
                  max="1440" /></label
              ><label
                >备好未履约提醒<input
                  v-model="storeSettings.alert_ready_minutes"
                  type="number"
                  min="1"
                  max="1440"
              /></label>
            </div>
          </section>
          <button class="primary settings-save" type="submit">保存店铺与规则</button>
        </form>
      </template>

      <template v-else-if="view === 'users'">
        <section class="resource-tools">
          <div>
            <p>共 {{ adminAccounts.length }} 个账号</p>
            <span>按岗位分配权限，停用后立即禁止登录。</span>
          </div>
          <button class="primary" @click="openUserForm()">＋ 新建管理员</button>
        </section>
        <section class="card table-card">
          <div class="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>用户名</th>
                  <th>角色</th>
                  <th>状态</th>
                  <th>最后登录</th>
                  <th>创建时间</th>
                  <th>操作</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="account in adminAccounts" :key="account.id">
                  <td>
                    <strong>{{ account.username }}</strong>
                  </td>
                  <td>{{ roleText[account.role_code] || account.role_code }}</td>
                  <td>{{ account.status === 'active' ? '启用' : '停用' }}</td>
                  <td>{{ account.last_login_at || '从未登录' }}</td>
                  <td>{{ account.created_at }}</td>
                  <td class="row-actions"><button @click="openUserForm(account)">编辑</button></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </template>

      <template v-else-if="view === 'logs'">
        <section class="filter-bar">
          <input v-model="logKeyword" placeholder="用户名、操作、对象ID或IP" /><button
            class="primary small"
            @click="loadView"
          >
            查询日志
          </button>
        </section>
        <section class="card table-card">
          <div class="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>时间</th>
                  <th>操作人</th>
                  <th>操作</th>
                  <th>对象</th>
                  <th>IP 地址</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="log in operationLogs" :key="log.id">
                  <td>{{ log.created_at }}</td>
                  <td>{{ log.username || '系统' }}</td>
                  <td>
                    <strong>{{ log.action }}</strong>
                  </td>
                  <td>{{ log.target_type || '—' }} {{ log.target_id || '' }}</td>
                  <td>{{ log.ip || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="!operationLogs.length" class="empty">暂无操作日志</div>
        </section>
      </template>

      <template v-else-if="isResource && resourceRows"
        ><section class="resource-tools">
          <div>
            <p>共 {{ resourceRows.total }} 条记录</p>
            <span>支持新建、编辑和停用；历史数据不会物理删除。</span>
          </div>
          <button class="primary" @click="openCreate">＋ 新建</button>
        </section>
        <section class="card table-card">
          <div class="table-scroll">
            <table>
              <thead>
                <tr>
                  <th v-for="column in resourceColumns[view as ResourceName]" :key="column.key">
                    {{ column.label }}
                  </th>
                  <th>操作</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in resourceRows.data" :key="String(row.id)">
                  <td v-for="column in resourceColumns[view as ResourceName]" :key="column.key">
                    {{ resourceDisplay(row, column.key) }}
                  </td>
                  <td class="row-actions">
                    <button @click="openEdit(row)">编辑</button
                    ><button v-if="view === 'sessions'" @click="openInventory(row)">菜单库存</button
                    ><button class="danger-link" @click="disableResource(row)">停用</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="!resourceRows.data.length" class="empty">暂无配置数据</div>
        </section></template
      >
    </main>

    <div v-if="userFormOpen" class="modal-mask" @click.self="userFormOpen = false">
      <section class="editor-modal">
        <header>
          <div>
            <p class="eyebrow">ADMIN ACCOUNT</p>
            <h2>{{ editingUserId ? '编辑管理员' : '新建管理员' }}</h2>
          </div>
          <button @click="userFormOpen = false">×</button>
        </header>
        <form @submit.prevent="saveUser">
          <label
            >用户名<b>*</b
            ><input
              v-model="userForm.username"
              :disabled="Boolean(editingUserId)"
              autocomplete="off"
              placeholder="3–60位字母或数字" /></label
          ><label
            >{{ editingUserId ? '新密码（不修改请留空）' : '登录密码'
            }}<b v-if="!editingUserId">*</b
            ><input
              v-model="userForm.password"
              type="password"
              autocomplete="new-password"
              placeholder="至少8位" /></label
          ><label
            >角色<b>*</b
            ><select v-model="userForm.role_code">
              <option v-for="(label, key) in roleText" :key="key" :value="key">{{ label }}</option>
            </select></label
          ><label v-if="editingUserId"
            >账号状态<select v-model="userForm.status">
              <option value="active">启用</option>
              <option value="inactive">停用</option>
            </select></label
          >
          <footer>
            <button type="button" class="secondary" @click="userFormOpen = false">取消</button
            ><button class="primary" type="submit">保存账号</button>
          </footer>
        </form>
      </section>
    </div>

    <div v-if="selectedOrder" class="drawer-mask" @click.self="selectedOrder = null">
      <aside class="order-drawer">
        <section class="receipt">
          <header>
            <strong>BUDAPEST</strong>
            <span>{{ settingValue('restaurant_name') }}</span>
            <small>订单履约小票</small>
          </header>
          <div class="receipt-code">
            <span>{{ fulfillmentCodeLabel(selectedOrder.fulfillment_type) }}</span>
            <b>{{ selectedOrder.fulfillment_code || '—' }}</b>
          </div>
          <div v-if="selectedOrder.status === 'cancelled'" class="receipt-cancelled">
            此订单已取消
          </div>
          <dl>
            <div>
              <dt>订单号</dt>
              <dd>{{ selectedOrder.order_no }}</dd>
            </div>
            <div>
              <dt>订单类型</dt>
              <dd>{{ fulfillmentText[selectedOrder.fulfillment_type] }}</dd>
            </div>
            <div>
              <dt>用餐日期</dt>
              <dd>
                {{ selectedOrder.service_date }} ·
                {{ selectedOrder.meal_type === 'lunch' ? '午餐' : '晚餐' }}
              </dd>
            </div>
            <div>
              <dt>预约时段</dt>
              <dd>
                {{
                  orderSlots.find((slot) => Number(slot.id) === selectedOrder?.time_slot_id)
                    ?.start_time || '—'
                }}–{{
                  orderSlots.find((slot) => Number(slot.id) === selectedOrder?.time_slot_id)
                    ?.end_time || '—'
                }}
              </dd>
            </div>
            <div v-if="selectedOrder.fulfillment_type === 'dine_in'">
              <dt>人数 / 桌号</dt>
              <dd>
                {{ selectedOrder.people_count }} 人 / {{ selectedOrder.table_no || '待分配' }}
              </dd>
            </div>
            <div>
              <dt>联系人</dt>
              <dd>{{ selectedOrder.customer_name }} {{ selectedOrder.customer_phone }}</dd>
            </div>
            <div v-if="selectedOrder.address">
              <dt>配送地址</dt>
              <dd>{{ selectedOrder.address }}</dd>
            </div>
          </dl>
          <div class="receipt-items">
            <div v-for="item in selectedOrder.items" :key="item.id">
              <span>{{ item.product_name }} × {{ item.quantity }}</span
              ><b>{{ money(item.total_amount) }}</b>
            </div>
          </div>
          <dl class="receipt-totals">
            <div>
              <dt>商品小计</dt>
              <dd>{{ money(selectedOrder.subtotal_amount || selectedOrder.payable_amount) }}</dd>
            </div>
            <div v-if="Number(selectedOrder.delivery_fee)">
              <dt>配送费</dt>
              <dd>{{ money(selectedOrder.delivery_fee) }}</dd>
            </div>
            <div>
              <dt>应付合计</dt>
              <dd class="receipt-total">{{ money(selectedOrder.payable_amount) }}</dd>
            </div>
            <div>
              <dt>收款状态</dt>
              <dd>
                {{
                  selectedOrder.payment_status === 'paid'
                    ? '已收款'
                    : selectedOrder.payment_status === 'refunded'
                      ? '已退款'
                      : '未收款'
                }}
              </dd>
            </div>
          </dl>
          <p v-if="selectedOrder.remark" class="receipt-note">
            <b>备注：</b>{{ selectedOrder.remark }}
          </p>
          <p v-if="selectedOrder.cancel_reason" class="receipt-note">
            <b>取消原因：</b>{{ selectedOrder.cancel_reason }}
          </p>
          <footer>
            <span>下单：{{ selectedOrder.created_at }}</span
            ><span>打印：{{ printTime }}</span
            ><b>{{ settingValue('receipt_footer') }}</b>
          </footer>
        </section>
        <div class="drawer-head">
          <div>
            <p class="eyebrow">ORDER DETAIL</p>
            <h2>{{ selectedOrder.order_no }}</h2>
          </div>
          <div>
            <button class="print-receipt-button" title="打印订单小票" @click="printOrder">
              ▣ 打印小票</button
            ><button @click="selectedOrder = null">×</button>
          </div>
        </div>
        <div class="order-status">
          <div>
            <span :class="`status-tag ${selectedOrder.status}`">{{
              displayStatusText(selectedOrder.status)
            }}</span
            ><span class="status-tag">{{
              fulfillmentStatusText[selectedOrder.fulfillment_status]
            }}</span
            ><span :class="`payment-tag ${selectedOrder.payment_status}`">{{
              selectedOrder.payment_status === 'paid'
                ? '已收款'
                : selectedOrder.payment_status === 'refunded'
                  ? '已退款'
                  : '未收款'
            }}</span>
          </div>
          <strong>{{ money(selectedOrder.payable_amount) }}</strong>
        </div>
        <div
          v-if="
            canManageOrders &&
            selectedOrder.payment_status === 'unpaid' &&
            selectedOrder.status !== 'cancelled'
          "
          class="payment-callout unpaid"
        >
          <div>
            <span>当前待收款</span><strong>{{ money(selectedOrder.payable_amount) }}</strong
            ><small>收到现金或线下转账后点击确认</small>
          </div>
          <button class="primary" @click="updatePayment('paid')">✓ 确认收款</button>
        </div>
        <div
          v-else-if="canManageOrders && selectedOrder.payment_status === 'paid'"
          class="payment-callout paid"
        >
          <div>
            <span>✓ 已确认收款</span><strong>{{ money(selectedOrder.payable_amount) }}</strong>
          </div>
          <button class="refund-button" @click="updatePayment('refunded')">登记退款</button>
        </div>
        <section>
          <h3>顾客与履约</h3>
          <dl>
            <div>
              <dt>联系人</dt>
              <dd>{{ selectedOrder.customer_name }}</dd>
            </div>
            <div>
              <dt>手机号</dt>
              <dd>{{ selectedOrder.customer_phone }}</dd>
            </div>
            <div>
              <dt>方式</dt>
              <dd>{{ fulfillmentText[selectedOrder.fulfillment_type] }}</dd>
            </div>
            <div>
              <dt>{{ fulfillmentCodeLabel(selectedOrder.fulfillment_type) }}</dt>
              <dd class="drawer-code">{{ selectedOrder.fulfillment_code || '—' }}</dd>
            </div>
            <div v-if="selectedOrder.fulfillment_type === 'dine_in'">
              <dt>就餐桌号</dt>
              <dd>{{ selectedOrder.table_no || '待分配' }}</dd>
            </div>
            <div v-if="selectedOrder.address">
              <dt>地址</dt>
              <dd>{{ selectedOrder.address }}</dd>
            </div>
            <div>
              <dt>备注</dt>
              <dd>{{ selectedOrder.remark || '无' }}</dd>
            </div>
          </dl>
        </section>
        <section v-if="canManageOrders" class="operation-panel">
          <h3>履约操作</h3>
          <label
            >预约时段<select v-model.number="selectedSlotId">
              <option v-for="slot in orderSlots" :key="String(slot.id)" :value="Number(slot.id)">
                {{ String(slot.start_time).slice(0, 5) }}–{{ String(slot.end_time).slice(0, 5) }} ·
                {{ slot.used_capacity }}/{{ slot.capacity }}
              </option>
            </select></label
          ><button
            v-if="selectedSlotId !== selectedOrder.time_slot_id"
            class="secondary"
            @click="rescheduleOrder"
          >
            保存新时段</button
          ><label
            v-if="
              selectedOrder.fulfillment_type === 'dine_in' &&
              selectedOrder.fulfillment_status === 'waiting_arrival'
            "
            >分配桌台<select v-model.number="selectedTableId">
              <option :value="0">请选择桌台</option>
              <option
                v-for="table in diningTables"
                :key="String(table.id)"
                :value="Number(table.id)"
              >
                {{ table.table_no }} · {{ table.capacity }}人
              </option>
            </select></label
          ><button
            v-if="
              canAdvanceFulfillmentState(selectedOrder.status, selectedOrder.fulfillment_status)
            "
            class="primary operation-button"
            @click="advanceFulfillment"
          >
            {{ fulfillmentActionText[nextFulfillment[selectedOrder.fulfillment_status]!] }}
          </button>
        </section>
        <section>
          <h3>商品明细</h3>
          <div v-for="item in selectedOrder.items" :key="item.id" class="order-item">
            <span>{{ item.product_name }} × {{ item.quantity }}</span
            ><strong>{{ money(item.total_amount) }}</strong>
          </div>
        </section>
        <section>
          <h3>状态记录</h3>
          <div v-for="log in groupedStatusLogs" :key="log.id" class="timeline">
            <i></i>
            <div>
              <strong>{{ log.label }}</strong
              ><small>{{ log.created_at }}</small>
            </div>
          </div>
        </section>
        <footer v-if="canManageOrders">
          <button
            v-if="!['completed', 'cancelled'].includes(selectedOrder.status)"
            class="danger"
            @click="cancelAdminOrder"
          >
            取消订单</button
          ><button v-if="nextStatus[selectedOrder.status]" class="primary" @click="advanceOrder">
            {{ nextStatusAction[selectedOrder.status] }}
          </button>
        </footer>
      </aside>
    </div>

    <div v-if="editorOpen" class="modal-mask" @click.self="editorOpen = false">
      <section class="editor-modal">
        <header>
          <div>
            <p class="eyebrow">RESOURCE EDITOR</p>
            <h2>{{ editorId ? '编辑' : '新建' }}{{ titles[view] }}</h2>
          </div>
          <button @click="editorOpen = false">×</button>
        </header>
        <form @submit.prevent="saveResource">
          <template v-for="field in formFields[view as ResourceName]" :key="field.key"
            ><label v-if="field.type === 'checkbox'" class="check-field"
              ><input
                v-model="editorData[field.key]"
                type="checkbox"
                true-value="1"
                false-value="0"
              />{{ editorFieldLabel(field) }}</label
            ><label v-else
              >{{ editorFieldLabel(field) }}<b v-if="field.required">*</b
              ><textarea
                v-if="field.type === 'textarea'"
                :value="String(editorData[field.key] ?? '')"
                @input="setEditorField(field.key, $event)"
              ></textarea
              ><select
                v-else-if="field.type === 'select'"
                :value="String(editorData[field.key] ?? '')"
                @change="setEditorField(field.key, $event)"
              >
                <option v-for="option in field.options" :key="option.value" :value="option.value">
                  {{ option.label }}
                </option></select
              ><input
                v-else
                :value="String(editorData[field.key] ?? '')"
                :type="field.type || 'text'"
                :step="field.type === 'number' ? '1' : undefined"
                @input="setEditorField(field.key, $event)" /></label
          ></template>
          <section
            v-if="view === 'products' && editorData.type === 'package'"
            class="package-editor"
          >
            <div>
              <strong>套餐组成</strong
              ><button type="button" @click="addPackageItem">＋ 添加单品</button>
            </div>
            <div v-for="(item, index) in packageItems" :key="index" class="package-row">
              <select v-model.number="item.product_id">
                <option :value="0">请选择单品</option>
                <option
                  v-for="product in productChoices.filter(
                    (choice) => choice.type === 'dish' && choice.id !== editorId,
                  )"
                  :key="product.id"
                  :value="product.id"
                >
                  {{ localizedProductName(product) }}
                </option></select
              ><input v-model.number="item.quantity" type="number" min="1" max="99" /><button
                type="button"
                @click="removePackageItem(index)"
              >
                ×
              </button>
            </div>
            <small v-if="!packageItems.length">尚未添加套餐组成</small>
          </section>
          <div v-if="editorError" class="form-error">{{ editorError }}</div>
          <footer>
            <button type="button" class="secondary" @click="editorOpen = false">取消</button
            ><button class="primary">保存</button>
          </footer>
        </form>
      </section>
    </div>

    <div v-if="inventoryOpen" class="modal-mask" @click.self="inventoryOpen = false">
      <section class="editor-modal inventory-modal">
        <header>
          <div>
            <p class="eyebrow">SESSION MENU</p>
            <h2>餐次菜单与库存</h2>
          </div>
          <button @click="inventoryOpen = false">×</button>
        </header>
        <p class="modal-tip">启用餐次可售商品，库存留空表示不限量。</p>
        <div class="inventory-head"><span>商品</span><span>餐次售价</span><span>库存</span></div>
        <div v-for="item in inventoryItems" :key="item.product_id" class="inventory-row">
          <label class="check-field"
            ><input v-model="item.enabled" type="checkbox" /><span>{{ item.name }}</span></label
          ><input v-model="item.sale_price" type="number" min="0" step="1" /><input
            v-model="item.stock"
            type="number"
            min="0"
            placeholder="不限"
          />
        </div>
        <footer>
          <button class="secondary" @click="inventoryOpen = false">取消</button
          ><button class="primary" @click="saveInventory">保存菜单库存</button>
        </footer>
      </section>
    </div>
  </div>
</template>

<style scoped lang="scss">
.admin-language-switch {
  width: 112px;
  border: 1px solid #dedbd3;
  border-radius: 9px;
  background: #fff;
  padding: 9px 7px;
  font-size: 11px;
  color: #302b27;
  outline: none;
}
.notification-toggle{display:grid;place-items:center;width:38px;height:38px;border:1px solid #dedbd3;border-radius:50%;background:#fff;font-size:16px}.notification-toggle.active{border-color:#9bb4a2;background:#edf5ef}
.login-language-switch{position:fixed;right:22px;top:20px;z-index:5}
$red: #a52a25;
$ink: #292823;
$paper: #f3f1ec;
$cream: #fffdf8;
$line: #dedbd3;
$muted: #7f7b72;
.login-page {
  min-height: 100vh;
  display: grid;
  grid-template-columns: 1.1fr 0.9fr;
  background: $ink;
}
.login-brand {
  padding: 9vw;
  display: flex;
  flex-direction: column;
  justify-content: center;
  color: white;
  background:
    radial-gradient(circle at 20% 20%, #65423a 0, transparent 32%),
    linear-gradient(140deg, #302e29, #1e1d1a);
}
.brand-badge {
  width: 58px;
  height: 58px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: $red;
  font: 38px Georgia;
}
.login-brand p,
.eyebrow {
  font-size: 11px;
  letter-spacing: 2px;
  font-weight: 700;
  color: $red;
}
.login-brand p {
  color: #d2b9ad;
  margin-top: 30px;
}
.login-brand h1 {
  font:
    400 clamp(42px, 5vw, 72px)/1.15 Georgia,
    'Songti SC';
  margin: 14px 0 24px;
}
.login-brand span {
  color: #bbb6ae;
}
.login-card {
  align-self: center;
  justify-self: center;
  width: min(420px, 82%);
  background: $cream;
  border-radius: 24px;
  padding: 40px;
}
.login-card h2 {
  font-size: 34px;
  margin: 8px 0;
}
.login-card > p:not(.eyebrow) {
  color: $muted;
}
.login-card label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  margin: 20px 0;
}
.login-card input,
.filter-bar input,
.filter-bar select,
.date-picker input {
  width: 100%;
  border: 1px solid $line;
  background: white;
  border-radius: 10px;
  padding: 12px;
  margin-top: 7px;
  outline: none;
}
.login-card input:focus {
  border-color: $red;
}
.primary {
  border: 0;
  background: $red;
  color: white;
  border-radius: 10px;
  padding: 12px 18px;
  font-weight: 700;
}
.login-card .primary {
  width: 100%;
  margin: 8px 0 20px;
}
.login-card small {
  color: #999;
}
.form-error,
.alert {
  background: #f7dfda;
  color: #8e2924;
  padding: 11px;
  border-radius: 9px;
  font-size: 13px;
}
.admin-shell {
  min-height: 100vh;
}
.admin-shell > aside {
  position: fixed;
  inset: 0 auto 0 0;
  width: 244px;
  background: $ink;
  color: white;
  padding: 24px 16px;
  display: flex;
  flex-direction: column;
  z-index: 50;
}
.side-brand {
  display: flex;
  gap: 12px;
  align-items: center;
  padding: 0 10px 25px;
}
.side-brand > span {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: $red;
  font: 25px Georgia;
}
.side-brand strong,
.side-brand small {
  display: block;
}
.side-brand strong {
  font: 22px Georgia;
}
.side-brand small {
  font-size: 10px;
  color: #999;
  letter-spacing: 1px;
}
.admin-shell nav {
  overflow: auto;
  flex: 1;
}
.admin-shell nav section > p {
  font-size: 10px;
  color: #89867f;
  letter-spacing: 1.5px;
  margin: 20px 12px 7px;
}
.admin-shell nav button {
  width: 100%;
  border: 0;
  background: none;
  color: #bdb9b0;
  border-radius: 9px;
  padding: 10px 12px;
  text-align: left;
  display: flex;
  gap: 12px;
  align-items: center;
  margin: 2px 0;
}
.admin-shell nav button i {
  font-style: normal;
  font-size: 18px;
  width: 22px;
  text-align: center;
}
.admin-shell nav button:hover,
.admin-shell nav button.active {
  background: #3b3934;
  color: white;
}
.admin-shell nav button.active {
  box-shadow: inset 3px 0 $red;
}
.user-card {
  border-top: 1px solid #45423d;
  padding: 18px 8px 0;
  display: flex;
  align-items: center;
  gap: 10px;
}
.user-card > div {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: #57534c;
}
.user-card span {
  flex: 1;
}
.user-card strong,
.user-card small {
  display: block;
}
.user-card small {
  font-size: 10px;
  color: #999;
}
.user-card button {
  border: 0;
  background: none;
  color: #aaa;
  font-size: 20px;
}
.workspace {
  margin-left: 244px;
  padding: 30px 36px;
  min-height: 100vh;
}
.workspace > header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 28px;
}
.workspace header h1 {
  font:
    400 34px Georgia,
    'Songti SC';
  margin: 4px 0;
}
.workspace header .eyebrow {
  margin: 0;
}
.date-picker {
  font-size: 11px;
  color: $muted;
}
.date-picker input {
  display: block;
  margin-top: 5px;
}
.menu-button {
  display: none;
}
.metrics {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 15px;
}
.metrics article,
.card {
  background: $cream;
  border: 1px solid $line;
  border-radius: 15px;
}
.metrics article {
  padding: 20px;
}
.metrics article span,
.metrics article small,
.metrics article strong {
  display: block;
}
.metrics article span {
  font-size: 12px;
  color: $muted;
}
.metrics article strong {
  font: 34px Georgia;
  margin: 12px 0 7px;
}
.metrics article small {
  font-size: 10px;
  color: #aaa;
}
.metrics .accent {
  background: $red;
  color: white;
  border-color: $red;
}
.metrics .accent span,
.metrics .accent small {
  color: #f0cbc8;
}
.dashboard-grid {
  display: grid;
  grid-template-columns: 1.1fr 0.9fr;
  gap: 15px;
  margin-top: 15px;
}
.dashboard-grid.lower {
  grid-template-columns: 1.2fr 0.8fr;
}
.card {
  padding: 20px;
}
.card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid $line;
  padding-bottom: 13px;
  margin-bottom: 12px;
}
.card-head h2 {
  font-size: 16px;
  margin: 0;
}
.card-head span {
  font-size: 10px;
  color: $muted;
}
.status-list > div {
  display: flex;
  justify-content: space-between;
  padding: 9px 0;
}
.status-list span {
  font-size: 13px;
}
.dot {
  display: inline-block;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #aaa;
  margin-right: 8px;
}
.dot.pending {
  background: #d59a31;
}
.dot.preparing {
  background: $red;
}
.dot.completed {
  background: #56815f;
}
.fulfillment-chart {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  padding-top: 15px;
}
.fulfillment-chart > div {
  text-align: center;
  background: $paper;
  border-radius: 11px;
  padding: 19px 6px;
}
.fulfillment-chart strong,
.fulfillment-chart span {
  display: block;
}
.fulfillment-chart strong {
  font: 28px Georgia;
}
.fulfillment-chart span {
  font-size: 11px;
  color: $muted;
  margin-top: 5px;
}
table {
  width: 100%;
  border-collapse: collapse;
}
th {
  text-align: left;
  color: $muted;
  font-size: 10px;
  letter-spacing: 0.5px;
  padding: 10px;
  border-bottom: 1px solid $line;
}
td {
  padding: 12px 10px;
  border-bottom: 1px solid #ebe8e1;
  font-size: 12px;
}
td small {
  display: block;
  color: #999;
  margin-top: 4px;
}
.capacity {
  margin: 13px 0;
}
.capacity > div {
  display: flex;
  justify-content: space-between;
  font-size: 11px;
}
.capacity progress {
  width: 100%;
  height: 6px;
  border: 0;
  margin-top: 7px;
}
.capacity progress::-webkit-progress-bar {
  background: #e5e1d9;
  border-radius: 5px;
}
.capacity progress::-webkit-progress-value {
  background: $red;
  border-radius: 5px;
}
.filter-bar {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr auto;
  gap: 10px;
  margin-bottom: 14px;
}
.filter-bar input,
.filter-bar select {
  margin: 0;
}
.small {
  padding: 8px 20px;
}
.table-card {
  padding: 0;
  overflow: hidden;
}
.table-scroll {
  overflow: auto;
}
.table-card tbody tr {
  cursor: pointer;
}
.table-card tbody tr:hover {
  background: #faf6ef;
}
.status-tag {
  display: inline-block;
  padding: 5px 8px;
  border-radius: 14px;
  background: #e9e6df;
  color: #605e59;
  font-size: 10px;
}
.status-tag.pending {
  background: #f8e8c9;
  color: #96661a;
}
.status-tag.preparing {
  background: #f2d8d4;
  color: #922d27;
}
.status-tag.completed {
  background: #dceadd;
  color: #3d7046;
}
.status-tag.cancelled {
  background: #e5e4e1;
  color: #777;
}
.empty {
  text-align: center;
  color: #999;
  padding: 35px;
  font-size: 13px;
}
.workbench-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 14px;
}
.section-title {
  display: flex;
  justify-content: space-between;
  align-items: end;
  margin: 4px 0 14px;
}
.section-title h2 {
  margin: 3px 0 0;
}
.section-title > span {
  font-size: 12px;
  color: $muted;
}
.summary-title {
  margin-top: 28px;
}
.kitchen-order-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.kitchen-order {
  display: grid;
  grid-template-columns: 95px 1fr auto;
  align-items: center;
  gap: 14px;
  border-left: 4px solid #c99b48;
}
.kitchen-order.preparing {
  border-left-color: $red;
  background: #fff8f2;
}
.kitchen-code span {
  display: block;
  font-size: 10px;
  color: $muted;
}
.kitchen-code strong {
  display: block;
  color: $red;
  font-size: 23px;
  letter-spacing: 2px;
}
.kitchen-order h3 {
  font-size: 13px;
  margin: 0 0 6px;
}
.kitchen-order p,
.kitchen-order small {
  display: block;
  margin: 0;
  color: $muted;
  font-size: 11px;
}
.kitchen-order button {
  white-space: nowrap;
}
.exception-panel {
  margin-bottom: 18px;
  border-color: #e1c17c;
}
.exception-panel .card-head {
  margin-bottom: 10px;
}
.exception-panel .card-head h2 {
  margin: 3px 0 0;
}
.exception-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}
.exception-item {
  display: grid;
  grid-template-columns: 30px 1fr auto;
  gap: 10px;
  align-items: center;
  text-align: left;
  border: 1px solid #ead7aa;
  background: #fffaf0;
  border-radius: 10px;
  padding: 11px;
  color: inherit;
}
.exception-item > i {
  display: grid;
  place-items: center;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: #c88d32;
  color: white;
  font-style: normal;
  font-weight: 900;
}
.exception-item.urgent {
  border-color: #dfaaa6;
  background: #fff5f3;
}
.exception-item.urgent > i {
  background: $red;
}
.exception-item div {
  display: grid;
  gap: 2px;
}
.exception-item span,
.exception-item small {
  font-size: 10px;
  color: $muted;
}
.exception-item > b {
  font-size: 11px;
  color: $red;
  white-space: nowrap;
}
.settings-form {
  display: grid;
  gap: 14px;
  max-width: 920px;
}
.settings-form > .card {
  padding: 20px;
}
.settings-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}
.settings-grid.three {
  grid-template-columns: repeat(3, 1fr);
}
.settings-grid label {
  display: grid;
  gap: 7px;
  font-size: 11px;
  font-weight: 700;
}
.settings-grid .wide {
  grid-column: 1/-1;
}
.settings-grid input,
.settings-grid textarea {
  width: 100%;
  border: 1px solid $line;
  border-radius: 9px;
  background: white;
  padding: 11px;
  outline: none;
}
.settings-grid textarea {
  min-height: 76px;
  resize: vertical;
}
.settings-grid input:focus,
.settings-grid textarea:focus {
  border-color: $red;
}
.settings-save {
  justify-self: end;
}
.work-card {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 16px;
}
.work-index {
  font: 28px Georgia;
  color: #c9c4ba;
}
.work-card h3 {
  margin: 0 0 7px;
}
.work-card p {
  font-size: 11px;
  color: $muted;
  margin: 3px 0;
}
.work-card > strong {
  color: $red;
}
.resource-tools {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;
}
.resource-tools p {
  margin: 0;
  font-weight: 700;
}
.resource-tools span {
  font-size: 11px;
  color: $muted;
}
.resource-tools button:disabled {
  opacity: 0.5;
}
.loading {
  position: fixed;
  top: 20px;
  left: 50%;
  z-index: 90;
  background: $ink;
  color: white;
  padding: 9px 16px;
  border-radius: 20px;
  font-size: 11px;
}
.toast {
  position: fixed;
  top: 20px;
  left: 50%;
  z-index: 95;
  transform: translateX(-50%);
  background: #496d50;
  color: white;
  padding: 10px 18px;
  border-radius: 20px;
}
.alert {
  margin-bottom: 15px;
  display: flex;
  justify-content: space-between;
}
.alert button {
  border: 0;
  background: none;
}
.drawer-mask {
  position: fixed;
  inset: 0;
  z-index: 80;
  background: #16130f77;
}
.order-drawer {
  position: absolute;
  right: 0;
  top: 0;
  bottom: 0;
  width: min(480px, 100%);
  background: $cream;
  padding: 25px;
  overflow: auto;
  box-shadow: -15px 0 50px #0002;
}
.drawer-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  border-bottom: 1px solid $line;
}
.drawer-head h2 {
  font-size: 17px;
  margin: 5px 0 20px;
}
.drawer-head button {
  border: 0;
  background: none;
  font-size: 27px;
}
.drawer-head .print-receipt-button {
  border: 1px solid #9bb4a2;
  background: #edf5ef;
  color: #31583b;
  border-radius: 8px;
  padding: 7px 10px;
  margin-right: 8px;
  font-size: 12px;
  font-weight: 700;
}
.print-list-button {
  border: 0;
  background: transparent;
  color: #6f6257;
  padding: 6px 8px;
  white-space: nowrap;
  font-size: 11px;
  font-weight: 700;
}
.order-status {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px 0;
}
.order-status > strong {
  font: 27px Georgia;
  color: $red;
}
.payment-callout {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  border: 1px solid #e2c273;
  border-radius: 12px;
  background: #fff8df;
  padding: 14px;
  margin-bottom: 12px;
}
.payment-callout > div {
  display: grid;
  gap: 3px;
}
.payment-callout span {
  font-size: 12px;
  font-weight: 800;
}
.payment-callout strong {
  color: $red;
  font-size: 20px;
}
.payment-callout small {
  color: $muted;
  font-size: 10px;
}
.payment-callout .primary {
  white-space: nowrap;
  padding: 11px 15px;
}
.payment-callout.paid {
  border-color: #a9c7ae;
  background: #edf6ef;
}
.payment-callout.paid strong {
  color: #31583b;
}
.refund-button {
  border: 0;
  background: transparent;
  color: #76685c;
  text-decoration: underline;
  font-size: 11px;
}
.order-drawer section {
  border-top: 1px solid $line;
  padding: 15px 0;
}
.order-drawer section h3 {
  font-size: 13px;
}
.order-drawer dl div {
  display: grid;
  grid-template-columns: 90px 1fr;
  padding: 6px 0;
  font-size: 12px;
}
.order-drawer dt {
  color: $muted;
}
.order-drawer dd {
  margin: 0;
  text-align: right;
}
.order-item {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  padding: 7px 0;
}
.timeline {
  display: flex;
  gap: 10px;
  padding: 7px;
}
.timeline i {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: $red;
  margin-top: 4px;
}
.timeline strong,
.timeline small {
  display: block;
  font-size: 11px;
}
.timeline small {
  color: #999;
  margin-top: 3px;
}
.order-drawer footer {
  position: sticky;
  bottom: -25px;
  background: $cream;
  border-top: 1px solid $line;
  padding: 15px 0 25px;
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}
.danger {
  border: 1px solid $red;
  background: transparent;
  color: $red;
  border-radius: 10px;
  padding: 11px 16px;
}
.side-mask {
  display: none;
}
@media (max-width: 900px) {
  .admin-shell > aside {
    transform: translateX(-100%);
    transition: 0.2s;
  }
  .admin-shell > aside.open {
    transform: none;
  }
  .side-mask {
    display: block;
    position: fixed;
    inset: 0;
    background: #0005;
    z-index: 40;
  }
  .workspace {
    margin-left: 0;
    padding: 22px;
  }
  .menu-button {
    display: block;
    border: 0;
    background: none;
    font-size: 23px;
  }
  .workspace > header > div {
    flex: 1;
    margin-left: 12px;
  }
  .metrics {
    grid-template-columns: repeat(2, 1fr);
  }
  .dashboard-grid,
  .dashboard-grid.lower {
    grid-template-columns: 1fr;
  }
  .workbench-grid {
    grid-template-columns: 1fr;
  }
  .kitchen-order-grid {
    grid-template-columns: 1fr;
  }
  .exception-grid {
    grid-template-columns: 1fr;
  }
  .settings-grid,
  .settings-grid.three {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 600px) {
  .login-page {
    grid-template-columns: 1fr;
    background: $paper;
  }
  .login-brand {
    display: none;
  }
  .login-card {
    width: calc(100% - 28px);
    padding: 26px;
  }
  .workspace {
    padding: 16px;
  }
  .workspace > header {
    align-items: flex-end;
  }
  .workspace header h1 {
    font-size: 26px;
  }
  .date-picker {
    width: 130px;
  }
  .metrics {
    grid-template-columns: 1fr 1fr;
    gap: 8px;
  }
  .metrics article {
    padding: 15px;
  }
  .metrics article strong {
    font-size: 25px;
  }
  .filter-bar {
    grid-template-columns: 1fr 1fr;
  }
  .filter-bar input {
    grid-column: 1/3;
  }
  .work-card {
    grid-template-columns: auto 1fr;
  }
  .work-card > strong {
    grid-column: 2;
  }
  .resource-tools span {
    display: none;
  }
}
</style>
