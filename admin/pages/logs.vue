<script setup lang="ts">
import { useApi } from '~/composables/useApi'
import { useToastStore } from '~/stores/toast'

const api = useApi()
const toast = useToastStore()

const limit = ref(200)
const q = ref('')

const lines = ref<string[]>([])
const returned = ref(0)
const loading = ref(false)

const metaQuery = ref<string | null>(null)

const autoRefresh = ref(true)
let intervalId: number | null = null
let qDebounceId: number | null = null

async function fetchLogs() {
  loading.value = true
  try {
    const res = await api.get<{ data: string[]; meta: { limit: number; returned: number; query: string | null } }>(
      '/admin/logs/system',
      {
        limit: limit.value,
        ...(q.value.trim() ? { q: q.value.trim() } : {}),
      },
    )

    lines.value = res.data
    returned.value = res.meta.returned
    metaQuery.value = res.meta.query
  } catch {
    toast.show('Could not load system logs.')
  } finally {
    loading.value = false
  }
}

function startAutoRefresh() {
  stopAutoRefresh()
  if (!autoRefresh.value) return

  intervalId = window.setInterval(() => {
    // If the user is typing in the filter, avoid racing network calls.
    if (qDebounceId !== null) return
    fetchLogs()
  }, 8000)
}

function stopAutoRefresh() {
  if (intervalId !== null) window.clearInterval(intervalId)
  intervalId = null
}

watch(autoRefresh, (v) => {
  if (v) startAutoRefresh()
  else stopAutoRefresh()
})

watch(q, () => {
  if (qDebounceId !== null) window.clearTimeout(qDebounceId)

  qDebounceId = window.setTimeout(() => {
    qDebounceId = null
    fetchLogs()
  }, 450)
})

onMounted(async () => {
  await fetchLogs()
  startAutoRefresh()
})

onBeforeUnmount(() => {
  stopAutoRefresh()
  if (qDebounceId !== null) window.clearTimeout(qDebounceId)
})
</script>

<template>
  <div style="display: flex; flex-direction: column; height: 100vh; overflow: hidden">
    <header class="flex flex-none items-center justify-between border-b border-[#EBEDF0] bg-white px-7 py-4">
      <div>
        <div class="mb-0.5 text-[11.5px] font-semibold uppercase tracking-[.06em] text-[#8A8F98]">
          AIHD Lab · Admin
        </div>
        <h1 class="m-0 text-[28px] font-extrabold tracking-[-.03em]">System Log Viewer</h1>
      </div>

      <div class="flex items-center gap-2.5">
        <label class="inline-flex items-center gap-2 rounded-full bg-[#FAFAFA] px-3 py-2 text-[13px] font-semibold text-[#4B4C4D]">
          <input type="checkbox" v-model="autoRefresh" class="h-4 w-4">
          Auto refresh
        </label>

        <button
          class="inline-flex h-11 items-center gap-2 rounded-full border-0 bg-[#5639E5] px-[18px] text-sm font-semibold text-white shadow-[0_2px_10px_rgba(86,57,229,.28)]"
          :disabled="loading"
          @click="fetchLogs"
        >
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12a9 9 0 1 1-3-6.7" />
            <path d="M21 3v6h-6" />
          </svg>
          {{ loading ? 'Loading…' : 'Refresh' }}
        </button>
      </div>
    </header>

    <div class="flex-none border-b border-[#F1F1F3] px-7 py-3">
      <div class="flex flex-wrap items-center gap-2.5">
        <input
          v-model="q"
          placeholder="Filter (substring)…"
          class="h-[46px] flex-1 min-w-[240px] rounded-lg border border-[#DCE0E7] bg-white px-4 text-[14px] focus:border-[#5639E5]"
        >

        <select v-model="limit" class="h-[46px] w-[140px] rounded-lg border border-[#DCE0E7] bg-white px-3 text-[14px] font-semibold">
          <option :value="100">100 lines</option>
          <option :value="200">200 lines</option>
          <option :value="500">500 lines</option>
        </select>

        <div class="ml-auto text-[13px] font-semibold text-[#8A8F98]">
          {{ returned }} lines
          <span v-if="metaQuery" class="text-[#7E2EE5]">· q={{ metaQuery }}</span>
        </div>
      </div>
    </div>

    <div class="flex-1 min-h-0 overflow-auto px-7 py-5">
      <pre
        class="rounded-xl bg-[#19191A] p-4 font-mono text-[12px] leading-[1.55] text-[#C9F0D8]"
      >{{ lines.length ? lines.join('\n') : 'No log lines to display yet.' }}</pre>
    </div>
  </div>
</template>

