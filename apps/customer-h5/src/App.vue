<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { api } from './api'
import { language, setLanguage, tr, useDomI18n, type Language } from './i18n'
import { useCountdown } from './composables/useCountdown'
import { useGoogleMapsAddress } from './composables/useGoogleMapsAddress'
import type { CartItem, FulfillmentType, MenuData, Order, Session } from './types'
import { readStorage, writeStorage } from './utils/storage'

type Page = 'menu' | 'checkout' | 'success' | 'lookup'
const page = ref<Page>('menu')
const sessions = ref<Session[]>([])
const selectedSessionId = ref(0)
const menu = ref<MenuData | null>(null)
interface SavedOrder {
  order_no: string
  token: string
  created_at: string
  amount: string
  status: string
  fulfillment_code?: string
  fulfillment_type?: FulfillmentType
  table_no?: string
}
const cart = ref<CartItem[]>(readStorage<CartItem[]>('budapest-cart', []))
const savedOrders = ref<SavedOrder[]>(readStorage<SavedOrder[]>('budapest-orders', []))
const loading = ref(true)
const submitting = ref(false)
const error = ref('')
const toast = ref('')
const notificationEnabled = ref(localStorage.getItem('budapest-customer-notifications') === '1' && typeof Notification !== 'undefined' && Notification.permission === 'granted')
const order = ref<Order | null>(null)
const storeSettings = reactive({
  restaurant_name: '布达佩斯餐厅',
  restaurant_subtitle: 'HUNGARIAN KITCHEN',
  restaurant_phone: '',
  restaurant_address: '',
  logo_url: '',
  cancellation_rule: '餐厅开始制作前可随时取消；开始制作后请联系餐厅处理。',
})
const preview = ref<{
  payable_amount: string
  subtotal_amount: string
  delivery_fee: string
} | null>(null)
const form = reactive({
  fulfillment_type: 'takeaway' as FulfillmentType,
  time_slot_id: 0,
  customer_name: '',
  customer_phone: '',
  address: '',
  delivery_lat: undefined as number | undefined,
  delivery_lng: undefined as number | undefined,
  delivery_zone_id: 1,
  people_count: 2,
  remark: '',
})
const formErrors = reactive<Record<string, string>>({})
const lookup = reactive({ order_no: '', token: '' })
const cartOpen = ref(false)
const confirmOpen = ref(false)
const activeCategory = ref('')
const addressInput = ref<HTMLInputElement | null>(null)
const addressMap = ref<HTMLElement | null>(null)
const mapsAddress = useGoogleMapsAddress((selected) => {
  form.address = selected.address
  form.delivery_lat = selected.lat
  form.delivery_lng = selected.lng
  delete formErrors.address
})
const { seconds: cutoffSeconds, text: cutoffText, start: startCountdown } = useCountdown()
useDomI18n()

const cartCount = computed(() => cart.value.reduce((sum, item) => sum + item.quantity, 0))
const cartTotalCents = computed(() =>
  cart.value.reduce(
    (sum, item) => sum + Math.round(Number(item.product.sale_price) * 100) * item.quantity,
    0,
  ),
)
const cartTotal = computed(() => cartTotalCents.value / 100)
const categories = computed(() => [
  ...new Set(menu.value?.products.map((p) => p.category_name) || []),
])
const availableSlots = computed(
  () =>
    menu.value?.time_slots.filter(
      (slot) =>
        slot.fulfillment_type === form.fulfillment_type && slot.used_capacity < slot.capacity,
    ) || [],
)
const selectedSession = computed(() =>
  sessions.value.find((item) => item.id === selectedSessionId.value),
)
const statusText: Record<string, string> = {
  pending: '等待餐厅确认',
  confirmed: '餐厅已确认',
  preparing: '正在制作',
  ready: '餐点已备好',
  fulfilling: '正在履约',
  completed: '订单已完成',
  cancelled: '订单已取消',
}

watch(cart, (value) => writeStorage('budapest-cart', value), { deep: true })
watch(language, () => {
  void api.store(language.value).then((settings) => Object.assign(storeSettings, settings))
  if (selectedSessionId.value) void loadMenu()
  if (order.value && lookup.token) void refreshCurrentOrder()
})
watch(
  () => form.fulfillment_type,
  async () => {
    form.time_slot_id = availableSlots.value[0]?.id || 0
    preview.value = null
    if (form.fulfillment_type === 'delivery' && page.value === 'checkout') {
      await nextTick()
      await mapsAddress
        .setup(addressInput.value, addressMap.value)
        .catch((e) => (error.value = messageOf(e)))
    }
  },
)

async function loadSessions() {
  loading.value = true
  error.value = ''
  try {
    const [sessionRows, settings] = await Promise.all([
      api.sessions(),
      api.store(language.value).catch(() => null),
    ])
    sessions.value = sessionRows
    if (settings) Object.assign(storeSettings, settings)
    if (!sessions.value.length) throw new Error('暂时没有可预约的餐次')
    selectedSessionId.value = sessions.value[0]!.id
    await loadMenu()
  } catch (e) {
    error.value = messageOf(e)
  } finally {
    loading.value = false
  }
}
async function loadMenu() {
  try {
    menu.value = await api.menu(selectedSessionId.value, language.value)
    activeCategory.value = categories.value[0] || ''
    startCountdown(menu.value.session.cutoff_at)
    form.time_slot_id = availableSlots.value[0]?.id || 0
  } catch (e) {
    error.value = messageOf(e)
  }
}
async function selectSession(id: number) {
  if (id === selectedSessionId.value) return
  if (cart.value.length && !confirm('切换餐次会清空当前购物车，是否继续？')) return
  cart.value = []
  selectedSessionId.value = id
  await loadMenu()
}
function changeQuantity(product: MenuData['products'][number], delta: number) {
  const item = cart.value.find((entry) => entry.product.id === product.id)
  if (!item && delta > 0) cart.value.push({ product, quantity: 1 })
  else if (item) {
    item.quantity = Math.min(99, item.quantity + delta)
    if (item.quantity <= 0) cart.value = cart.value.filter((entry) => entry !== item)
  }
}
function quantityOf(id: number) {
  return cart.value.find((item) => item.product.id === id)?.quantity || 0
}
function scrollToCategory(category: string) {
  activeCategory.value = category
  document
    .getElementById(`category-${category}`)
    ?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
function orderPayload() {
  return {
    session_id: selectedSessionId.value,
    fulfillment_type: form.fulfillment_type,
    time_slot_id: form.time_slot_id,
    customer_name: form.customer_name.trim(),
    customer_phone: form.customer_phone.trim(),
    address: form.address.trim() || undefined,
    delivery_lat: form.fulfillment_type === 'delivery' ? form.delivery_lat : undefined,
    delivery_lng: form.fulfillment_type === 'delivery' ? form.delivery_lng : undefined,
    delivery_zone_id: form.fulfillment_type === 'delivery' ? form.delivery_zone_id : undefined,
    people_count: form.fulfillment_type === 'dine_in' ? form.people_count : undefined,
    remark: form.remark.trim(),
    items: cart.value.map((item) => ({ product_id: item.product.id, quantity: item.quantity })),
  }
}
async function toCheckout() {
  page.value = 'checkout'
  window.scrollTo(0, 0)
  await nextTick()
  await mapsAddress.setup(addressInput.value, addressMap.value).catch((e) => (error.value = messageOf(e)))
  await refreshPreview()
}
async function refreshPreview() {
  preview.value = null
  if (!form.time_slot_id || !form.customer_name || !form.customer_phone) return
  try {
    preview.value = (await api.preview(orderPayload())).amounts
  } catch (e) {
    error.value = messageOf(e)
  }
}
async function requestSubmit() {
  error.value = ''
  Object.keys(formErrors).forEach((key) => delete formErrors[key])
  if (!form.time_slot_id) formErrors.time_slot_id = '请选择可用的预约时段'
  if (!form.customer_name.trim()) formErrors.customer_name = '请填写联系人姓名'
  if (!/^[0-9+() -]{6,30}$/.test(form.customer_phone.trim()))
    formErrors.customer_phone = '请输入正确的手机号码'
  if (form.fulfillment_type === 'delivery' && !form.address.trim())
    formErrors.address = '请填写完整配送地址'
  if (form.fulfillment_type === 'dine_in' && (form.people_count < 1 || form.people_count > 50))
    formErrors.people_count = '用餐人数应为 1–50 人'
  const firstInvalid = Object.keys(formErrors)[0]
  if (firstInvalid) {
    await nextTick()
    const field = document.querySelector<HTMLElement>(`[data-field="${firstInvalid}"]`)
    field?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    field?.querySelector<HTMLElement>('input, select, textarea')?.focus({ preventScroll: true })
    return
  }
  await refreshPreview()
  if (error.value) return
  confirmOpen.value = true
}
async function submitOrder() {
  confirmOpen.value = false
  submitting.value = true
  try {
    const result = await api.createOrder({ ...orderPayload(), idempotency_key: crypto.randomUUID() }, language.value)
    order.value = result
    if (result.query_token) {
      localStorage.setItem(`order-token:${result.order_no}`, result.query_token)
      lookup.order_no = result.order_no
      lookup.token = result.query_token
      savedOrders.value = [
        {
          order_no: result.order_no,
          token: result.query_token,
          created_at: new Date().toISOString(),
          amount: result.payable_amount,
          status: result.status,
          fulfillment_code: result.fulfillment_code,
          fulfillment_type: result.fulfillment_type,
          table_no: result.table_no,
        },
        ...savedOrders.value.filter((item) => item.order_no !== result.order_no),
      ].slice(0, 20)
      writeStorage('budapest-orders', savedOrders.value)
    }
    cart.value = []
    page.value = 'success'
    window.scrollTo(0, 0)
  } catch (e) {
    error.value = messageOf(e)
  } finally {
    submitting.value = false
  }
}
async function lookupOrder() {
  error.value = ''
  if (!lookup.order_no || !lookup.token) {
    error.value = '请输入订单号和查询凭证'
    return
  }
  loading.value = true
  try {
    order.value = await api.order(lookup.order_no.trim(), lookup.token.trim(), language.value)
    updateSavedOrder(order.value)
  } catch (e) {
    error.value = messageOf(e)
  } finally {
    loading.value = false
  }
}
async function cancelOrder() {
  if (!order.value || !confirm('确定取消这张订单吗？')) return
  try {
    order.value = await api.cancel(order.value.order_no, lookup.token, language.value)
    updateSavedOrder(order.value)
    showToast('订单已取消')
  } catch (e) {
    error.value = messageOf(e)
  }
}
function openSavedOrder(item: SavedOrder) {
  lookup.order_no = item.order_no
  lookup.token = item.token
  void lookupOrder()
}
function removeSavedOrder(orderNo: string) {
  savedOrders.value = savedOrders.value.filter((item) => item.order_no !== orderNo)
  writeStorage('budapest-orders', savedOrders.value)
}
function updateSavedOrder(value: Order) {
  const item = savedOrders.value.find((entry) => entry.order_no === value.order_no)
  if (item)
    Object.assign(item, {
      status: value.status,
      fulfillment_code: value.fulfillment_code,
      fulfillment_type: value.fulfillment_type,
      table_no: value.table_no,
    })
  writeStorage('budapest-orders', savedOrders.value)
}
function fulfillmentLabel(value: FulfillmentType) {
  return { delivery: '配送到家', takeaway: '到店自取', dine_in: '到店堂食' }[value]
}
function fulfillmentCodeLabel(value: FulfillmentType) {
  return { delivery: '配送码', takeaway: '取餐码', dine_in: '就餐码' }[value]
}
function showToast(text: string) {
  toast.value = text
  setTimeout(() => (toast.value = ''), 2200)
}
async function toggleNotifications() {
  if (notificationEnabled.value) {
    notificationEnabled.value = false; localStorage.removeItem('budapest-customer-notifications'); showToast('订单提醒已关闭'); return
  }
  if (typeof Notification === 'undefined') { error.value = '当前浏览器不支持系统通知'; return }
  const permission = await Notification.requestPermission()
  if (permission !== 'granted') { error.value = '请在浏览器设置中允许通知'; return }
  notificationEnabled.value = true; localStorage.setItem('budapest-customer-notifications', '1'); showToast('订单提醒已开启')
}
function messageOf(e: unknown) {
  return e instanceof Error ? e.message : '发生未知错误'
}
function formatDate(value: string) {
  const locale = { zh: 'zh-CN', en: 'en-GB', hu: 'hu-HU' }[language.value]
  return new Intl.DateTimeFormat(locale, {
    month: 'short',
    day: 'numeric',
    weekday: 'short',
  }).format(new Date(`${value}T12:00:00`))
}
function mealLabel(value: string) {
  return value === 'lunch' ? '午餐' : '晚餐'
}
function time(value: string) {
  return value.slice(0, 5)
}
function money(value: string | number | undefined) {
  return new Intl.NumberFormat('hu-HU', {
    style: 'currency',
    currency: 'HUF',
    maximumFractionDigits: 0,
  }).format(Number(value || 0))
}

let orderRefreshTimer: number | undefined
async function refreshCurrentOrder() {
  if (
    !order.value ||
    !lookup.token ||
    document.hidden ||
    !['success', 'lookup'].includes(page.value)
  )
    return
  try {
    const previousStatus = order.value.status
    const previousTable = order.value.table_no
    order.value = await api.order(order.value.order_no, lookup.token, language.value)
    if (notificationEnabled.value && (order.value.status !== previousStatus || order.value.table_no !== previousTable)) {
      new Notification(tr(statusText[order.value.status] || '订单状态已更新'), { body: tr(`${fulfillmentCodeLabel(order.value.fulfillment_type)} ${order.value.fulfillment_code || ''}${order.value.table_no ? ` · 桌号 ${order.value.table_no}` : ''}`) })
    }
    updateSavedOrder(order.value)
  } catch {
    // 网络临时不可用时保留当前订单信息，下一轮自动重试。
  }
}

onMounted(() => {
  void loadSessions()
  orderRefreshTimer = window.setInterval(() => void refreshCurrentOrder(), 10_000)
})
onUnmounted(() => window.clearInterval(orderRefreshTimer))
</script>

<template>
  <div class="app-shell">
    <header class="topbar">
      <button v-if="page !== 'menu'" class="icon-btn" aria-label="返回选菜" @click="page = 'menu'">
        ←
      </button>
      <div class="brand" @click="page = 'menu'">
        <span class="brand-mark">B</span><span>{{ storeSettings.restaurant_name }}</span>
      </div>
      <select
        class="language-switch"
        :value="language"
        aria-label="切换语言"
        @change="setLanguage(($event.target as HTMLSelectElement).value as Language)"
      >
        <option value="zh">🇨🇳 中文</option>
        <option value="en">🇬🇧 English</option>
        <option value="hu">🇭🇺 Magyar</option>
      </select>
      <button class="customer-notification-toggle" :title="notificationEnabled ? '关闭订单提醒' : '开启订单提醒'" @click="toggleNotifications">{{ notificationEnabled ? '🔔' : '🔕' }}</button>
      <button
        class="orders-link"
        aria-label="查看订单"
        title="查看订单"
        @click="
          page = 'lookup';
          order = null
        "
      >
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path
            d="M7 3.75h10a1.5 1.5 0 0 1 1.5 1.5v15L16 18.5l-2 2-2-2-2 2-2-2-2.5 1.75v-15A1.5 1.5 0 0 1 7 3.75Z"
          />
          <path d="M8.5 8h7M8.5 12h7M8.5 16h4" />
        </svg>
        <span>订单</span>
      </button>
    </header>

    <main>
      <div v-if="toast" class="toast">{{ toast }}</div>
      <div v-if="error" class="error-banner">
        <span>{{ error }}</span
        ><button @click="error = ''">×</button>
      </div>

      <section v-if="page === 'menu'" class="menu-page">
        <div class="hero">
          <p class="eyebrow">{{ storeSettings.restaurant_subtitle }} · 每日鲜制</p>
          <h1>今天，吃点<br /><em>认真做的饭。</em></h1>
          <p class="hero-copy">提前预订，准时享用。支持配送、自取与堂食。</p>
        </div>

        <div v-if="loading" class="state-card">正在准备今日菜单…</div>
        <template v-else-if="menu">
          <div class="session-strip">
            <button
              v-for="session in sessions"
              :key="session.id"
              :class="{ active: session.id === selectedSessionId }"
              @click="selectSession(session.id)"
            >
              <strong>{{ formatDate(session.service_date) }}</strong
              ><span>{{ mealLabel(session.meal_type) }}</span>
            </button>
          </div>
          <div class="service-note" :class="{ closed: !cutoffSeconds }">
            <span class="pulse"></span> {{ cutoffSeconds ? '距下单截止' : '本餐次已截止' }}
            <strong v-if="cutoffSeconds">{{ cutoffText }}</strong>
          </div>

          <nav class="category-nav" aria-label="菜单分类">
            <button
              v-for="category in categories"
              :key="category"
              :class="{ active: activeCategory === category }"
              @click="scrollToCategory(category)"
            >
              {{ category }}
            </button>
          </nav>

          <section
            v-for="category in categories"
            :id="`category-${category}`"
            :key="category"
            class="category-section"
          >
            <div class="section-title">
              <h2>{{ category }}</h2>
              <span
                >{{ menu.products.filter((p) => p.category_name === category).length }}
                {{ tr('道菜') }}</span
              >
            </div>
            <article
              v-for="product in menu.products.filter((p) => p.category_name === category)"
              :key="product.id"
              class="product-card"
              :class="{ soldout: product.sold_out }"
            >
              <div class="product-visual">
                <span>{{ product.type === 'package' ? '套餐' : '单品' }}</span
                ><b>{{ product.name.slice(0, 1) }}</b>
              </div>
              <div class="product-info">
                <div>
                  <div class="product-name">{{ product.name }}</div>
                  <p>{{ product.description || '每日新鲜制作，数量有限' }}</p>
                </div>
                <div class="product-bottom">
                  <strong>{{ money(product.sale_price) }}</strong>
                  <span v-if="product.sold_out" class="sold-label">已售罄</span>
                  <div v-else class="stepper">
                    <button
                      v-if="quantityOf(product.id)"
                      aria-label="减少数量"
                      @click="changeQuantity(product, -1)"
                    >
                      −</button
                    ><span v-if="quantityOf(product.id)">{{ quantityOf(product.id) }}</span
                    ><button
                      aria-label="增加数量"
                      :disabled="!cutoffSeconds"
                      @click="changeQuantity(product, 1)"
                    >
                      ＋
                    </button>
                  </div>
                </div>
              </div>
            </article>
          </section>
        </template>
      </section>

      <section v-else-if="page === 'checkout'" class="checkout-page">
        <p class="eyebrow">CONFIRM ORDER</p>
        <h1 class="page-title">确认订单</h1>
        <div class="panel">
          <h2>享用方式</h2>
          <div class="fulfillment-grid">
            <button
              v-for="item in [
                { v: 'delivery', i: '↗', t: '配送到家' },
                { v: 'takeaway', i: '⌂', t: '到店自取' },
                { v: 'dine_in', i: '◇', t: '到店堂食' },
              ]"
              :key="item.v"
              :class="{ active: form.fulfillment_type === item.v }"
              @click="form.fulfillment_type = item.v as FulfillmentType"
            >
              <b>{{ item.i }}</b
              ><span>{{ item.t }}</span>
            </button>
          </div>
        </div>
        <div class="panel">
          <h2>预约信息</h2>
          <label data-field="time_slot_id" :class="{ invalid: formErrors.time_slot_id }"
            >预约时段<select
              v-model.number="form.time_slot_id"
              @change="
                delete formErrors.time_slot_id;
                refreshPreview()
              "
            >
              <option v-for="slot in availableSlots" :key="slot.id" :value="slot.id">
                {{ time(slot.start_time) }}–{{ time(slot.end_time) }}（余
                {{ slot.capacity - slot.used_capacity }}）
              </option></select
            ><span v-if="formErrors.time_slot_id" class="field-error">{{
              formErrors.time_slot_id
            }}</span></label
          >
          <label
            v-if="form.fulfillment_type === 'dine_in'"
            data-field="people_count"
            :class="{ invalid: formErrors.people_count }"
            >用餐人数<input
              v-model.number="form.people_count"
              type="number"
              min="1"
              max="50"
              @input="delete formErrors.people_count"
            /><span v-if="formErrors.people_count" class="field-error">{{
              formErrors.people_count
            }}</span></label
          >
          <label data-field="customer_name" :class="{ invalid: formErrors.customer_name }"
            >联系人<input
              v-model="form.customer_name"
              maxlength="100"
              placeholder="请输入姓名"
              @input="delete formErrors.customer_name"
              @blur="refreshPreview"
            /><span v-if="formErrors.customer_name" class="field-error">{{
              formErrors.customer_name
            }}</span></label
          >
          <label data-field="customer_phone" :class="{ invalid: formErrors.customer_phone }"
            >手机号码<input
              v-model="form.customer_phone"
              inputmode="tel"
              placeholder="如 +36 30 123 4567"
              @input="delete formErrors.customer_phone"
              @blur="refreshPreview"
            /><span v-if="formErrors.customer_phone" class="field-error">{{
              formErrors.customer_phone
            }}</span></label
          >
          <label
            v-if="form.fulfillment_type === 'delivery'"
            data-field="address"
            :class="{ invalid: formErrors.address }"
            >配送地址<input
              ref="addressInput"
              v-model="form.address"
              maxlength="500"
              placeholder="街道、门牌号及补充说明"
              autocomplete="street-address"
              @input="delete formErrors.address"
            />
            <div v-if="mapsAddress.enabled" class="address-tools">
              <button type="button" class="location-button" @click="mapsAddress.locate(addressMap)">
                {{ mapsAddress.loadingLocation.value ? '正在定位…' : '⌖ 使用当前位置' }}
              </button>
              <small>可输入地址并从 Google 建议中选择</small>
            </div>
            <span v-if="mapsAddress.locationError.value" class="field-error">{{ mapsAddress.locationError.value }}</span>
            <div v-if="mapsAddress.enabled" ref="addressMap" class="address-map"></div
            ><span v-if="formErrors.address" class="field-error">{{
              formErrors.address
            }}</span></label
          >
          <label
            >订单备注 <small>选填</small
            ><textarea
              v-model="form.remark"
              maxlength="200"
              placeholder="口味、忌口等要求"
            ></textarea>
          </label>
        </div>
        <div class="panel">
          <h2>订单明细</h2>
          <div v-for="item in cart" :key="item.product.id" class="line-item">
            <span>{{ item.product.name }} × {{ item.quantity }}</span
            ><b>{{ money(Number(item.product.sale_price) * item.quantity) }}</b>
          </div>
          <div class="line-item muted">
            <span>配送费</span><b>{{ money(preview?.delivery_fee) }}</b>
          </div>
          <div class="total-line">
            <span>合计</span><strong>{{ money(preview?.payable_amount || cartTotal) }}</strong>
          </div>
        </div>
        <button
          class="primary wide"
          :disabled="submitting || !cutoffSeconds"
          @click="requestSubmit"
        >
          {{ submitting ? '正在提交…' : cutoffSeconds ? '确认下单' : '本餐次已截止' }}
        </button>
        <p class="fine-print">{{ storeSettings.cancellation_rule }}</p>
      </section>

      <section v-else-if="page === 'success' && order" class="success-page">
        <div class="success-icon">✓</div>
        <p class="eyebrow">ORDER RECEIVED</p>
        <h1>预约成功</h1>
        <p>餐厅收到订单后会尽快确认，请妥善保存查询凭证。</p>
        <div v-if="order.fulfillment_code" class="fulfillment-code">
          <span>{{ fulfillmentCodeLabel(order.fulfillment_type) }}</span
          ><strong>{{ order.fulfillment_code }}</strong
          ><small>到店或交接时请出示此码</small>
        </div>
        <div class="order-ticket">
          <span>订单号</span><strong>{{ order.order_no }}</strong
          ><span v-if="order.table_no">就餐桌号</span
          ><strong v-if="order.table_no">{{ order.table_no }}</strong
          ><span>应付金额</span><strong class="amount">{{ money(order.payable_amount) }}</strong
          ><span>当前状态</span><strong>{{ statusText[order.status] }}</strong>
        </div>
        <button class="primary wide" @click="page = 'lookup'">查看订单详情</button
        ><button
          class="text-btn"
          @click="
            page = 'menu';
            order = null
          "
        >
          继续点餐
        </button>
      </section>

      <section v-else class="lookup-page">
        <p class="eyebrow">MY ORDER</p>
        <h1 class="page-title">查询订单</h1>
        <p class="intro">输入下单成功时获得的订单号与查询凭证。</p>
        <div v-if="savedOrders.length" class="saved-orders">
          <div class="saved-title">
            <h2>本机订单</h2>
            <span>{{ savedOrders.length }} 张</span>
          </div>
          <article v-for="item in savedOrders" :key="item.order_no">
            <button class="saved-main" @click="openSavedOrder(item)">
              <span>{{ statusText[item.status] || item.status }}</span
              ><strong>{{ money(item.amount) }}</strong
              ><small v-if="item.fulfillment_code && item.fulfillment_type"
                >{{ fulfillmentCodeLabel(item.fulfillment_type) }}
                {{ item.fulfillment_code }}</small
              ><small>{{ item.order_no }}</small></button
            ><button
              class="remove-order"
              aria-label="删除本机记录"
              @click="removeSavedOrder(item.order_no)"
            >
              ×
            </button>
          </article>
        </div>
        <div class="panel lookup-form">
          <label>订单号<input v-model="lookup.order_no" placeholder="请输入订单号" /></label
          ><label>查询凭证<input v-model="lookup.token" placeholder="请输入查询凭证" /></label
          ><button class="primary wide" @click="lookupOrder">查询订单</button>
        </div>
        <div v-if="order" class="panel order-detail">
          <div class="status-head">
            <span>{{ statusText[order.status] || order.status }}</span
            ><small>{{ order.order_no }}</small>
          </div>
          <div v-if="order.fulfillment_code" class="detail-code">
            <span>{{ fulfillmentCodeLabel(order.fulfillment_type) }}</span
            ><strong>{{ order.fulfillment_code }}</strong>
          </div>
          <div v-if="order.table_no" class="detail-code">
            <span>就餐桌号</span><strong>{{ order.table_no }}</strong>
          </div>
          <div v-for="item in order.items" :key="item.id" class="line-item">
            <span>{{ item.product_name }} × {{ item.quantity }}</span
            ><b>{{ money(item.total_amount) }}</b>
          </div>
          <div class="total-line">
            <span>合计</span><strong>{{ money(order.payable_amount) }}</strong>
          </div>
          <button
            v-if="['pending', 'confirmed'].includes(order.status)"
            class="danger-btn"
            @click="cancelOrder"
          >
            取消订单
          </button>
        </div>
      </section>
    </main>

    <div v-if="page === 'menu' && cartCount" class="cart-bar">
      <button class="cart-summary" aria-label="打开购物车" title="购物车" @click="cartOpen = true">
        <span class="cart-icon"
          ><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L20.5 8H6" />
            <circle cx="10" cy="19" r="1.25" />
            <circle cx="17" cy="19" r="1.25" /></svg
          ><b>{{ cartCount }}</b></span
        ><strong>{{ money(cartTotal) }}</strong></button
      ><button :disabled="!cutoffSeconds" @click="toCheckout">
        {{ cutoffSeconds ? '去结算' : '已截止' }} <span>→</span>
      </button>
    </div>

    <div v-if="cartOpen" class="overlay" @click.self="cartOpen = false">
      <section class="bottom-sheet" role="dialog" aria-modal="true" aria-label="购物车">
        <div class="sheet-head">
          <h2>购物车</h2>
          <button @click="cart = []">清空</button>
        </div>
        <div v-for="item in cart" :key="item.product.id" class="cart-item">
          <div>
            <strong>{{ item.product.name }}</strong
            ><span>{{ money(item.product.sale_price) }}</span>
          </div>
          <div class="stepper">
            <button @click="changeQuantity(item.product, -1)">−</button
            ><span>{{ item.quantity }}</span
            ><button @click="changeQuantity(item.product, 1)">＋</button>
          </div>
        </div>
        <div v-if="!cart.length" class="empty-cart">购物车已清空</div>
        <button
          v-else
          class="primary wide"
          @click="
            cartOpen = false;
            toCheckout()
          "
        >
          去结算 · {{ money(cartTotal) }}
        </button>
      </section>
    </div>

    <div v-if="confirmOpen" class="overlay confirm-overlay" @click.self="confirmOpen = false">
      <section class="confirm-card" role="dialog" aria-modal="true">
        <p class="eyebrow">FINAL CHECK</p>
        <h2>请确认订单</h2>
        <dl>
          <div>
            <dt>餐次</dt>
            <dd>
              {{ formatDate(selectedSession?.service_date || '') }} ·
              {{ mealLabel(selectedSession?.meal_type || '') }}
            </dd>
          </div>
          <div>
            <dt>方式</dt>
            <dd>{{ fulfillmentLabel(form.fulfillment_type) }}</dd>
          </div>
          <div>
            <dt>联系人</dt>
            <dd>{{ form.customer_name }} · {{ form.customer_phone }}</dd>
          </div>
          <div>
            <dt>商品</dt>
            <dd>{{ cartCount }} 件</dd>
          </div>
          <div>
            <dt>应付</dt>
            <dd class="confirm-amount">{{ money(preview?.payable_amount || cartTotal) }}</dd>
          </div>
        </dl>
        <div class="confirm-actions">
          <button class="secondary" @click="confirmOpen = false">返回修改</button
          ><button class="primary" :disabled="submitting" @click="submitOrder">确认提交</button>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped lang="scss">
.language-switch {
  width: 102px;
  border: 1px solid #dfd5c5;
  border-radius: 18px;
  background: #fffdf8;
  padding: 7px 6px;
  font-size: 12px;
  color: #3a332d;
  outline: none;
}
.customer-notification-toggle{display:grid;place-items:center;width:36px;height:36px;flex:0 0 36px;border:1px solid #dfd5c5;border-radius:50%;background:#fffdf8;font-size:15px}
$red: #a52a25;
$ink: #25231f;
$paper: #f5f0e8;
$cream: #fffaf2;
$line: #ddd3c4;
.orders-link {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1px;
  border: 0;
  padding: 3px 6px;
  font-size: 10px;
}
.orders-link svg {
  width: 24px;
  height: 24px;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.7;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.cart-icon {
  position: relative;
  display: block;
  width: 31px;
  height: 28px;
}
.cart-icon svg {
  width: 28px;
  height: 28px;
  fill: none;
  stroke: white;
  stroke-width: 1.8;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.cart-icon b {
  position: absolute;
  right: -6px;
  top: -7px;
  display: grid;
  place-items: center;
  min-width: 18px;
  height: 18px;
  padding: 0 4px;
  border-radius: 10px;
  background: $red;
  color: white;
  font: 700 10px 'Noto Sans SC';
}
.cart-summary {
  display: flex !important;
  align-items: center;
  gap: 14px;
}
.cart-summary > strong {
  font-size: 20px !important;
}
.service-note strong {
  font-variant-numeric: tabular-nums;
  color: $red;
  margin-left: 4px;
}
.service-note.closed {
  color: $red;
}
.service-note.closed .pulse {
  background: $red;
}
.category-nav {
  position: sticky;
  top: 66px;
  z-index: 18;
  display: flex;
  gap: 8px;
  overflow: auto;
  padding: 10px 20px;
  background: rgba(245, 240, 232, 0.96);
  border-bottom: 1px solid $line;
  scrollbar-width: none;
}
.category-nav::-webkit-scrollbar {
  display: none;
}
.category-nav button {
  white-space: nowrap;
  border: 1px solid $line;
  background: $cream;
  border-radius: 20px;
  padding: 8px 18px;
  font-size: 13px;
}
.category-nav button.active {
  background: $red;
  border-color: $red;
  color: white;
}
.category-section {
  scroll-margin-top: 126px;
}
.stepper button:disabled,
.cart-bar button:disabled {
  opacity: 0.45;
}
.cart-bar .cart-summary {
  background: transparent;
  padding: 0;
  text-align: left;
}
.cart-summary span,
.cart-summary strong {
  display: block;
}
.cart-summary span {
  font-size: 11px;
  color: #c9c2b8;
  margin: 0;
}
.cart-summary strong {
  font-family: 'DM Serif Display';
  font-size: 22px;
}
.overlay {
  position: fixed;
  inset: 0;
  z-index: 70;
  background: #17130f99;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  padding: max(14px, env(safe-area-inset-bottom)) 14px;
}
.bottom-sheet {
  width: min(100%, 520px);
  max-height: 72vh;
  overflow: auto;
  background: $paper;
  border-radius: 22px 22px 16px 16px;
  padding: 20px;
}
.sheet-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid $line;
  padding-bottom: 12px;
}
.sheet-head h2 {
  font-family: 'DM Serif Display', 'Noto Sans SC';
  margin: 0;
}
.sheet-head button,
.remove-order {
  border: 0;
  background: none;
  color: $red;
}
.cart-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 15px 0;
  border-bottom: 1px solid $line;
}
.cart-item > div:first-child strong,
.cart-item > div:first-child span {
  display: block;
}
.cart-item > div:first-child span {
  color: $red;
  font-size: 13px;
  margin-top: 4px;
}
.empty-cart {
  text-align: center;
  color: #888;
  padding: 38px;
}
.confirm-overlay {
  align-items: center;
}
.confirm-card {
  width: min(100%, 440px);
  background: $cream;
  border-radius: 20px;
  padding: 24px;
}
.confirm-card h2 {
  font-family: 'DM Serif Display', 'Noto Sans SC';
  font-size: 28px;
  margin: 8px 0 16px;
}
.confirm-card dl {
  margin: 0;
}
.confirm-card dl div {
  display: flex;
  justify-content: space-between;
  gap: 20px;
  border-bottom: 1px solid $line;
  padding: 10px 0;
}
.confirm-card dt {
  color: #80786d;
}
.confirm-card dd {
  margin: 0;
  text-align: right;
  font-weight: 600;
}
.confirm-card .confirm-amount {
  font-family: 'DM Serif Display';
  font-size: 22px;
  color: $red;
}
.confirm-actions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-top: 20px;
}
.secondary {
  border: 1px solid $line;
  background: white;
  border-radius: 12px;
  font-weight: 600;
}
.saved-orders {
  margin: 24px 0;
}
.saved-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.saved-title h2 {
  font-family: 'DM Serif Display', 'Noto Sans SC';
  font-weight: 500;
}
.saved-title span {
  font-size: 12px;
  color: #888;
}
.saved-orders article {
  display: flex;
  align-items: center;
  background: $cream;
  border: 1px solid $line;
  border-radius: 13px;
  margin: 9px 0;
}
.saved-main {
  display: grid;
  grid-template-columns: 1fr auto;
  width: 100%;
  border: 0;
  background: none;
  text-align: left;
  padding: 13px;
}
.saved-main span {
  font-size: 13px;
  color: $red;
}
.saved-main strong {
  text-align: right;
}
.saved-main small {
  grid-column: 1/3;
  color: #8d8579;
  margin-top: 5px;
}
.remove-order {
  font-size: 20px;
  padding: 16px;
}
@media (max-width: 380px) {
  .hero h1,
  .page-title,
  .success-page h1 {
    font-size: 36px;
  }
  .product-card {
    grid-template-columns: 78px 1fr;
  }
  .product-visual {
    height: 82px;
  }
  .fulfillment-grid button span {
    font-size: 11px;
  }
}
@media (prefers-reduced-motion: reduce) {
  * {
    scroll-behavior: auto !important;
    transition: none !important;
  }
}
.invalid input,
.invalid select,
.invalid textarea {
  border-color: $red;
  background: #fff8f6;
}
.field-error {
  display: block;
  color: $red;
  font-size: 12px;
  font-weight: 500;
  margin-top: 6px;
}
.invalid {
  scroll-margin-top: 90px;
}
.fulfillment-code {
  background: $red;
  color: white;
  border-radius: 17px;
  padding: 17px;
  margin: 24px 0;
  text-align: center;
}
.fulfillment-code span,
.fulfillment-code small,
.fulfillment-code strong {
  display: block;
}
.fulfillment-code span {
  font-size: 12px;
  letter-spacing: 2px;
}
.fulfillment-code strong {
  font: 38px 'DM Serif Display';
  letter-spacing: 7px;
  margin: 6px 0;
}
.fulfillment-code small {
  color: #f0cac7;
}
.detail-code {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #f3e6da;
  border-radius: 10px;
  padding: 11px 13px;
  margin: 10px 0;
}
.detail-code span {
  font-size: 12px;
  color: #766a60;
}
.detail-code strong {
  font-size: 21px;
  color: $red;
  letter-spacing: 2px;
}
</style>
