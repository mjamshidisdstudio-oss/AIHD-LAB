<script setup lang="ts">
// design-reference/service-admin.dc.html lines 1283-1493 (Orders & logs tab).
// Two deviations from the design, both required by the prompt and flagged
// in the PR: (1) source/entry_mode filters added to the order list — the
// design only shows a status-derived stat row, no filter controls; (2) a
// "Webhook deliveries" sub-view added alongside the order list — the design
// only nests webhooks inside a specific order's detail, with no outcome or
// raw_body fields and no way to find a delivery that never resolved to an
// order at all (invalid_signature/unknown_order). The per-output "big
// viewer" carousel is simplified to a plain card list here.
import { useServiceDetailStore } from '~/stores/serviceDetail'
import { useToastStore } from '~/stores/toast'
import { useAuthStore } from '~/stores/auth'
import type { AdminOrder, AdminOrderListItem, AdminWebhookDelivery, EntryMode, OrderSource } from '~/types/api'

const detail = useServiceDetailStore()
const toast = useToastStore()
const api = useApi()
const auth = useAuthStore()
const config = useRuntimeConfig()

const view = ref<'orders' | 'webhooks'>('orders')

// ---- orders list ----
const orders = ref<AdminOrderListItem[]>([])
const stats = ref({ total: 0, completed: 0, failed: 0 })
const sourceFilter = ref<'' | OrderSource>('')
const entryModeFilter = ref<'' | EntryMode>('')
const selectedOrderId = ref<string | null>(null)
const selectedOrder = ref<AdminOrder | null>(null)
const loadingOrders = ref(false)

async function fetchOrders() {
  if (!detail.service) return
  loadingOrders.value = true
  try {
    const params: Record<string, string> = {}
    if (sourceFilter.value) params.source = sourceFilter.value
    if (entryModeFilter.value) params.entry_mode = entryModeFilter.value
    const res = await api.get<{ data: AdminOrderListItem[]; meta_stats: typeof stats.value }>(
      `/admin/services/${detail.service.id}/orders`,
      params,
    )
    orders.value = res.data
    stats.value = res.meta_stats
  } catch {
    toast.show('Could not load orders.')
  } finally {
    loadingOrders.value = false
  }
}

async function selectOrder(orderId: string) {
  selectedOrderId.value = orderId
  try {
    const res = await api.get<{ data: AdminOrder }>(`/admin/orders/${orderId}`)
    selectedOrder.value = res.data
  } catch {
    toast.show('Could not load order detail.')
  }
}

watchEffect(() => {
  // sourceFilter/entryModeFilter are read inside fetchOrders() before its
  // first await, so this single effect already re-fires on filter changes —
  // a separate watch() on the same refs would double the network call.
  if (detail.service && view.value === 'orders') fetchOrders()
})

// ---- webhook deliveries sub-view ----
const deliveries = ref<AdminWebhookDelivery[]>([])
const outcomeFilter = ref('')
const externalOrderIdFilter = ref('')
const expandedDeliveryId = ref<string | null>(null)

async function fetchDeliveries() {
  if (!detail.service) return
  try {
    const params: Record<string, string> = {}
    if (outcomeFilter.value) params.outcome = outcomeFilter.value
    if (externalOrderIdFilter.value) params.external_order_id = externalOrderIdFilter.value
    const res = await api.get<{ data: AdminWebhookDelivery[] }>(
      `/admin/services/${detail.service.id}/webhook-deliveries`,
      params,
    )
    deliveries.value = res.data
  } catch {
    toast.show('Could not load webhook deliveries.')
  }
}
watch(view, (v) => {
  if (v === 'webhooks') fetchDeliveries()
})
watch([outcomeFilter, externalOrderIdFilter], fetchDeliveries)

const OUTCOME_STYLE: Record<string, string> = {
  ingested: 'color:#168A40;background:#E8F8EE',
  duplicate: 'color:#8A8F98;background:#F4F5F7',
  invalid_signature: 'color:#D70D3E;background:#FDECEF',
  unknown_order: 'color:#D70D3E;background:#FDECEF',
  stale_attempt: 'color:#C9670C;background:#FFF1E0',
  validation_error: 'color:#D70D3E;background:#FDECEF',
  invalid_media_reference: 'color:#D70D3E;background:#FDECEF',
  failure_reported: 'color:#C9670C;background:#FFF1E0',
}

// ---- admin preview ----
const previewing = ref(false);
async function runAdminPreview() {
  if (!detail.selectedVersion) {
    toast.show('Select a version first.')
    return
  }
  previewing.value = true
  try {
    await api.post(`/admin/versions/${detail.selectedVersion.id}/preview-orders`, {
      entry_mode: 'wizard',
      answers: {},
    })
    toast.show('Admin preview order submitted — coin-free, strike-free, cap-free.')
    if (view.value === 'orders') await fetchOrders()
  } catch (e: any) {
    toast.show(e?.data?.message ?? 'Could not run admin preview.')
  } finally {
    previewing.value = false
  }
}

// ---- result download (streams through the admin download endpoint) ----
async function downloadResult(resultId: string) {
  try {
    const response = await fetch(`${config.public.apiBase}/admin/results/${resultId}/download`, {
      headers: auth.authHeader,
    })
    if (!response.ok) throw new Error()
    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = resultId
    a.click()
    URL.revokeObjectURL(url)
  } catch {
    toast.show('Could not download this result.')
  }
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-row overflow-hidden">
    <div class="flex w-[404px] flex-none flex-col border-r border-[#ECECEE] bg-white">
      <div class="border-b border-[#ECECEE] p-[18px]">
        <div class="mb-3 flex items-center justify-between">
          <div class="text-xl font-extrabold tracking-[-.02em]">Orders</div>
          <button
            class="rounded-full bg-[#5639E5] px-3 py-1.5 text-[11.5px] font-semibold text-white disabled:opacity-60"
            :disabled="previewing"
            @click="runAdminPreview"
          >
            {{ previewing ? 'Running…' : '▶ Admin preview' }}
          </button>
        </div>
        <div class="mb-3 flex gap-2">
          <div class="flex-1 rounded-xl bg-[#F5F5F6] px-2.5 py-2">
            <div class="text-lg font-bold">{{ stats.total }}</div>
            <div class="text-[10.5px] font-semibold text-[#8A8F98]">Total</div>
          </div>
          <div class="flex-1 rounded-xl bg-[#E7F6EC] px-2.5 py-2">
            <div class="text-lg font-bold text-[#168A40]">{{ stats.completed }}</div>
            <div class="text-[10.5px] font-semibold text-[#168A40]">Completed</div>
          </div>
          <div class="flex-1 rounded-xl bg-[#FDECEF] px-2.5 py-2">
            <div class="text-lg font-bold text-[#AE0A32]">{{ stats.failed }}</div>
            <div class="text-[10.5px] font-semibold text-[#AE0A32]">Failed</div>
          </div>
        </div>
        <div class="flex gap-1.5">
          <button class="flex-1 rounded-full px-3 py-1.5 text-[12px] font-semibold" :class="view === 'orders' ? 'bg-[#F0EDFE] text-[#5639E5]' : 'bg-[#F4F5F7] text-[#6B7280]'" @click="view = 'orders'">Orders</button>
          <button class="flex-1 rounded-full px-3 py-1.5 text-[12px] font-semibold" :class="view === 'webhooks' ? 'bg-[#F0EDFE] text-[#5639E5]' : 'bg-[#F4F5F7] text-[#6B7280]'" @click="view = 'webhooks'">Webhook deliveries</button>
        </div>
      </div>

      <template v-if="view === 'orders'">
        <div class="flex gap-2 border-b border-[#F1F1F3] px-[18px] py-3">
          <select v-model="sourceFilter" class="h-9 flex-1 rounded-lg border border-[#DCE0E7] bg-white px-2 text-[12px]">
            <option value="">All sources</option>
            <option value="site">Site</option>
            <option value="admin_preview">Admin preview</option>
          </select>
          <select v-model="entryModeFilter" class="h-9 flex-1 rounded-lg border border-[#DCE0E7] bg-white px-2 text-[12px]">
            <option value="">All modes</option>
            <option value="wizard">Wizard</option>
            <option value="chat">Chat</option>
          </select>
        </div>
        <div class="flex-1 overflow-auto">
          <div
            v-for="o in orders"
            :key="o.id"
            class="cursor-pointer border-b border-[#F1F1F3] px-[18px] py-3"
            :class="selectedOrderId === o.id ? 'bg-[#F6F4FF]' : ''"
            @click="selectOrder(o.id)"
          >
            <div class="mb-1.5 flex items-center justify-between gap-2">
              <span class="truncate font-mono text-[12.5px] font-bold">{{ o.id.slice(0, 8) }}</span>
              <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-[10.5px] font-bold" :class="{ 'bg-[#E8F8EE] text-[#168A40]': o.status === 'completed', 'bg-[#FDECEF] text-[#AE0A32]': o.status === 'failed', 'bg-[#F4F5F7] text-[#8A8F98]': o.status === 'processing' }">{{ o.status }}</span>
            </div>
            <div class="flex items-center justify-between gap-2 font-mono text-[10.5px] text-[#8A8F98]">
              <span class="min-w-0 truncate">{{ o.user_ref }}</span>
              <span class="flex-none">{{ new Date(o.created_at).toLocaleString() }}</span>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-1.5">
              <span class="inline-flex items-center gap-1 rounded-full bg-[#F5EEFF] px-2 py-0.5 text-[10px] font-bold text-[#7E2EE5]">{{ o.coins_charged }} credits</span>
              <span class="rounded-full bg-[#F4F5F7] px-2 py-0.5 text-[10px] font-bold text-[#4B4C4D]">{{ o.source }}</span>
              <span class="rounded-full bg-[#F4F5F7] px-2 py-0.5 text-[10px] font-bold text-[#4B4C4D]">{{ o.entry_mode }}</span>
              <span v-if="o.regenerated_from_order_id" class="rounded-full bg-[#F5EEFF] px-2 py-0.5 text-[10px] font-bold text-[#7E2EE5]">↻ regenerated</span>
            </div>
          </div>
          <div v-if="!loadingOrders && orders.length === 0" class="p-10 text-center text-[13px] text-[#969799]">No orders yet.</div>
        </div>
      </template>

      <template v-else>
        <div class="flex flex-col gap-2 border-b border-[#F1F1F3] px-[18px] py-3">
          <select v-model="outcomeFilter" class="h-9 rounded-lg border border-[#DCE0E7] bg-white px-2 text-[12px]">
            <option value="">All outcomes</option>
            <option value="ingested">Ingested</option>
            <option value="duplicate">Duplicate</option>
            <option value="invalid_signature">Invalid signature</option>
            <option value="unknown_order">Unknown order</option>
            <option value="stale_attempt">Stale attempt</option>
            <option value="validation_error">Validation error</option>
            <option value="invalid_media_reference">Invalid media reference</option>
            <option value="failure_reported">Failure reported</option>
          </select>
          <input v-model="externalOrderIdFilter" placeholder="Search by external_order_id…" class="h-9 rounded-lg border border-[#DCE0E7] bg-white px-2 text-[12px]">
        </div>
        <div class="flex-1 overflow-auto">
          <div v-for="d in deliveries" :key="d.id" class="border-b border-[#F1F1F3] px-[18px] py-3">
            <div class="mb-1.5 flex items-center justify-between gap-2">
              <span class="rounded-full px-2 py-0.5 text-[10.5px] font-bold" :style="OUTCOME_STYLE[d.outcome]">{{ d.outcome }}</span>
              <span class="font-mono text-[10.5px] text-[#8A8F98]">{{ d.http_status }}</span>
            </div>
            <div class="mb-1.5 font-mono text-[11px] text-[#4B4C4D]">{{ d.external_order_id ?? '(no external_order_id)' }}</div>
            <div class="mb-1.5 font-mono text-[10.5px] text-[#8A8F98]">{{ new Date(d.received_at).toLocaleString() }}</div>
            <button class="text-[11px] font-semibold text-[#5639E5]" @click="expandedDeliveryId = expandedDeliveryId === d.id ? null : d.id">
              {{ expandedDeliveryId === d.id ? 'Hide raw body' : 'Inspect raw body' }}
            </button>
            <pre v-if="expandedDeliveryId === d.id" class="mt-2 max-h-48 overflow-auto rounded-lg bg-[#19191A] p-3 font-mono text-[11px] text-[#C9F0D8]">{{ d.raw_body }}</pre>
          </div>
          <div v-if="deliveries.length === 0" class="p-10 text-center text-[13px] text-[#969799]">No webhook deliveries found.</div>
        </div>
      </template>
    </div>

    <div class="min-w-[320px] flex-1 overflow-auto bg-[#F6F7F9] p-6">
      <div v-if="view === 'orders' && selectedOrder" class="mx-auto flex max-w-[780px] flex-col gap-[18px]">
        <div class="rounded-[20px] border border-[#ECECEE] bg-white p-6 shadow-[0_1px_2px_rgba(133,151,171,.05)]">
          <div class="mb-4 flex items-center justify-between">
            <div class="flex flex-wrap items-center gap-2.5">
              <span class="text-base font-bold">Order</span>
              <span class="rounded-md bg-[#F0EDFE] px-2.5 py-1 font-mono text-[13px] text-[#5639E5]">{{ selectedOrder.id.slice(0, 8) }}</span>
              <span class="rounded-md bg-[#E8F8EE] px-2.5 py-1 text-[11px] font-bold text-[#168A40]">v{{ selectedOrder.version_no }}</span>
            </div>
            <span class="rounded-full px-2.5 py-1 text-[11px] font-bold" :class="{ 'bg-[#E8F8EE] text-[#168A40]': selectedOrder.status === 'completed', 'bg-[#FDECEF] text-[#AE0A32]': selectedOrder.status === 'failed', 'bg-[#F4F5F7] text-[#8A8F98]': selectedOrder.status === 'processing' }">{{ selectedOrder.status }}</span>
          </div>
          <div class="grid grid-cols-2 gap-x-6 gap-y-3.5">
            <div><div class="mb-0.5 text-[11px] font-semibold text-[#7D7E80]">user_ref</div><div class="font-mono text-[12.5px]">{{ selectedOrder.user_ref }}</div></div>
            <div><div class="mb-0.5 text-[11px] font-semibold text-[#7D7E80]">coins_charged</div><div class="text-[12.5px] font-bold text-[#7E2EE5]">{{ selectedOrder.coins_charged }} credits</div></div>
            <div><div class="mb-0.5 text-[11px] font-semibold text-[#7D7E80]">coin_txn_ref</div><div class="truncate font-mono text-[12.5px]">{{ selectedOrder.coin_txn_ref ?? '—' }}</div></div>
            <div><div class="mb-0.5 text-[11px] font-semibold text-[#7D7E80]">root_order_id</div><div class="truncate font-mono text-[12.5px]">{{ selectedOrder.root_order_id ?? '—' }}</div></div>
            <div><div class="mb-0.5 text-[11px] font-semibold text-[#7D7E80]">source</div><div class="font-mono text-[12.5px]">{{ selectedOrder.source }}</div></div>
            <div><div class="mb-0.5 text-[11px] font-semibold text-[#7D7E80]">entry_mode</div><div class="font-mono text-[12.5px]">{{ selectedOrder.entry_mode }}</div></div>
          </div>
        </div>

        <div class="rounded-[20px] border border-[#ECECEE] bg-white p-6 shadow-[0_1px_2px_rgba(133,151,171,.05)]">
          <div class="mb-3 text-[15px] font-bold">Outputs</div>
          <div v-if="selectedOrder.outputs.length === 0" class="rounded-xl bg-[#FAFAFA] p-8 text-center text-[13px] text-[#969799]">This order produced no outputs.</div>
          <div v-for="o in selectedOrder.outputs" :key="o.result_number" class="mb-2 rounded-xl border border-[#ECECEE] p-4">
            <div class="mb-2 flex items-center justify-between gap-2">
              <span class="font-bold">Output #{{ o.result_number }} <span class="font-normal text-[#8A8F98]">({{ o.type }})</span></span>
              <span class="rounded-full px-2 py-0.5 text-[11px] font-bold" :class="o.has_result ? 'bg-[#E8F8EE] text-[#168A40]' : 'bg-[#FDECEF] text-[#AE0A32]'">{{ o.has_result ? 'delivered' : (o.failure_stage ? 'failed' : 'pending') }}</span>
            </div>
            <div v-if="o.failure_stage" class="mb-2 rounded-lg bg-[#FDF2F4] p-2.5 text-[12px] text-[#8A1030]">Failure at stage: <span class="font-mono font-bold">{{ o.failure_stage }}</span></div>
            <div v-if="o.has_result" class="grid grid-cols-3 gap-3 text-[12px]">
              <div><div class="text-[10.5px] text-[#7D7E80]">source</div><div class="font-mono">{{ o.source }}</div></div>
              <div><div class="text-[10.5px] text-[#7D7E80]">latency</div><div class="font-bold text-[#168A40]">{{ o.latency_ms }}ms</div></div>
              <div><div class="text-[10.5px] text-[#7D7E80]">received_at</div><div class="font-mono">{{ o.received_at ? new Date(o.received_at).toLocaleString() : '—' }}</div></div>
              <div><div class="text-[10.5px] text-[#7D7E80]">downloads</div><div class="font-bold">{{ o.download_count }}</div></div>
            </div>
            <div v-if="o.text_value" class="mt-2 rounded-lg bg-[#FAFAFA] p-2.5 text-[12.5px]">{{ o.text_value }}</div>
            <button v-if="o.file_id" class="mt-2 rounded-lg bg-[#5639E5] px-3 py-1.5 text-[11.5px] font-semibold text-white" @click="downloadResult(o.result_id!)"> Download</button>
          </div>
        </div>

        <div class="rounded-[20px] border border-[#ECECEE] bg-white p-6 shadow-[0_1px_2px_rgba(133,151,171,.05)]">
          <div class="mb-1 text-[15px] font-bold">Order inputs</div>
          <div class="mb-3 text-[12px] text-[#7D7E80]">The values the user submitted for this order.</div>
          <div class="flex flex-col gap-2">
            <div v-for="inp in selectedOrder.inputs" :key="inp.id" class="flex items-center gap-2.5 rounded-lg bg-[#FAFAFA] p-2.5">
              <div class="w-[120px] flex-none">
                <div class="text-[12.5px] font-semibold">{{ inp.input_title }}</div>
                <div class="font-mono text-[10px] text-[#7D7E80]">{{ inp.input_slug }}</div>
              </div>
              <div class="min-w-0 flex-1 font-mono text-[12.5px]">
                {{ inp.value_text ?? (inp.value_bool !== null ? String(inp.value_bool) : inp.selected_options.map((o) => o.label).join(', ')) }}
              </div>
            </div>
            <div v-if="selectedOrder.inputs.length === 0" class="text-[12px] text-[#969799]">No inputs recorded.</div>
          </div>
        </div>

        <div class="mt-1.5 flex items-center gap-2">
          <span class="text-[15px] font-bold">Delivery trail</span>
          <span class="text-[11.5px] text-[#7D7E80]">raw API attempts &amp; webhooks behind the outputs above</span>
        </div>

        <div class="flex items-center gap-2"><span class="text-[13.5px] font-semibold text-[#4B4C4D]">Requests</span><span class="text-[11.5px] text-[#7D7E80]">({{ selectedOrder.requests.length }} attempts)</span></div>
        <div v-for="rq in selectedOrder.requests" :key="rq.id" class="rounded-2xl border border-[#ECECEE] bg-white p-5 shadow-[0_1px_2px_rgba(133,151,171,.05)]">
          <div class="mb-3.5 flex items-center justify-between">
            <div class="flex items-center gap-2">
              <span class="flex h-[26px] w-[26px] items-center justify-center rounded-lg bg-[#F0EDFE] text-[12px] font-bold text-[#5639E5]">#{{ rq.attempt_no }}</span>
              <span class="font-mono text-[12.5px] font-semibold">{{ rq.id.slice(0, 8) }}</span>
            </div>
            <span class="rounded-full px-2.5 py-1 text-[11px] font-bold" :class="{ 'bg-[#E8F8EE] text-[#168A40]': rq.status === 'completed', 'bg-[#FDECEF] text-[#AE0A32]': rq.status === 'failed', 'bg-[#F4F5F7] text-[#8A8F98]': !['completed', 'failed'].includes(rq.status) }">{{ rq.status }}</span>
          </div>
          <div class="mb-3.5 grid grid-cols-3 gap-x-4 gap-y-3 text-[12px]">
            <div><div class="mb-0.5 text-[10.5px] font-semibold text-[#7D7E80]">external_order_id</div><div class="truncate font-mono">{{ rq.external_order_id ?? '—' }}</div></div>
            <div><div class="mb-0.5 text-[10.5px] font-semibold text-[#7D7E80]">get_poll_count</div><div class="font-bold">{{ rq.get_poll_count }}</div></div>
            <div><div class="mb-0.5 text-[10.5px] font-semibold text-[#7D7E80]">failure_stage</div><div class="font-mono" :class="rq.failure_stage ? 'text-[#AE0A32]' : ''">{{ rq.failure_stage ?? '—' }}</div></div>
            <div><div class="mb-0.5 text-[10.5px] font-semibold text-[#7D7E80]">sent_at</div><div class="font-mono">{{ rq.sent_at ? new Date(rq.sent_at).toLocaleString() : '—' }}</div></div>
            <div><div class="mb-0.5 text-[10.5px] font-semibold text-[#7D7E80]">last_polled_at</div><div class="font-mono">{{ rq.last_polled_at ? new Date(rq.last_polled_at).toLocaleString() : '—' }}</div></div>
          </div>
          <div v-if="rq.results.length === 0" class="pb-0.5 pt-2 text-[11.5px] text-[#969799]">No results recorded for this request.</div>
          <div v-for="rs in rq.results" :key="rs.id" class="mt-2 flex items-center gap-3 rounded-lg bg-[#FAFAFA] p-2.5">
            <span class="flex h-6 w-6 flex-none items-center justify-center rounded-md bg-[#5639E5] text-[11px] font-bold text-white">{{ rs.result_number }}</span>
            <span class="rounded-md bg-[#E5F4FF] px-2 py-0.5 text-[11px] font-bold text-[#0073C6]">{{ rs.type }}</span>
            <span class="flex-1 text-[10.5px] text-[#7D7E80]">source: {{ rs.source }}</span>
            <span class="flex-none text-[11px] font-bold text-[#168A40]">{{ rs.latency_ms }}ms</span>
          </div>
        </div>

        <div class="mt-0.5 flex items-center gap-2"><span class="text-[13.5px] font-semibold text-[#4B4C4D]">Webhooks</span><span class="text-[11.5px] text-[#7D7E80]">({{ selectedOrder.requests.flatMap((r) => r.webhook_deliveries).length }} calls)</span></div>
        <div v-if="selectedOrder.requests.flatMap((r) => r.webhook_deliveries).length === 0" class="rounded-2xl border border-[#EBEDF0] bg-white p-5 text-[12.5px] text-[#969799]">No webhooks were called for this order.</div>
        <div v-for="wh in selectedOrder.requests.flatMap((r) => r.webhook_deliveries)" :key="wh.id" class="rounded-2xl border border-[#ECECEE] bg-white p-5 shadow-[0_1px_2px_rgba(133,151,171,.05)]">
          <div class="mb-3.5 flex items-center justify-between gap-2.5">
            <span class="rounded-md bg-[#F5EEFF] px-2 py-0.5 font-mono text-[12px] font-semibold text-[#7E2EE5]">{{ wh.outcome }}</span>
            <span class="font-mono text-[12px] font-bold">{{ wh.http_status }}</span>
          </div>
          <div class="text-[11px] text-[#7D7E80]">external_order_id</div>
          <div class="mb-2 truncate font-mono text-[12px]">{{ wh.external_order_id ?? '—' }}</div>
        </div>
      </div>

      <div v-else-if="view === 'orders'" class="flex h-full items-center justify-center text-[14px] text-[#969799]">
        Select an order to see its detail.
      </div>
    </div>
  </div>
</template>
