<script setup lang="ts">
// List screen — design-reference/service-admin.dc.html lines 586-668.
import { useCatalogStore } from '~/stores/catalog'
import { useToastStore } from '~/stores/toast'
import type { Service } from '~/types/api'

const catalog = useCatalogStore()
const toast = useToastStore()
const router = useRouter()

await useAsyncData('admin-services', () => catalog.fetchAll())

const query = ref('')

const ICON_PALETTE = [
  { bg: '#F0EDFE', fg: '#5639E5' },
  { bg: '#E5F4FF', fg: '#0073C6' },
  { bg: '#FFF1E0', fg: '#C9670C' },
  { bg: '#E8F8EE', fg: '#168A40' },
  { bg: '#FDECEF', fg: '#D70D3E' },
]
// The design doesn't define how a card's icon color is chosen — there is no
// schema column for it — so this is a stable, purely presentational hash.
function iconColors(service: Service) {
  const hash = [...service.id].reduce((sum, ch) => sum + ch.charCodeAt(0), 0)
  return ICON_PALETTE[hash % ICON_PALETTE.length]
}

const STATUS_STYLE: Record<string, string> = {
  active: 'color:#168A40;background:#E8F8EE',
  paused: 'color:#8A8F98;background:#F4F5F7',
  auto_disabled: 'color:#D70D3E;background:#FDECEF',
}
const STATUS_LABEL: Record<string, string> = {
  active: 'Active',
  paused: 'Paused',
  auto_disabled: 'Auto-disabled',
}

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return catalog.services
  return catalog.services.filter((s) => s.name.toLowerCase().includes(q) || s.slug.toLowerCase().includes(q))
})

const showNewService = ref(false)

function openService(service: Service) {
  router.push(`/services/${service.id}`)
}

async function toggleStatus(service: Service) {
  try {
    await catalog.toggleActive(service)
  } catch {
    toast.show('Could not update status.')
  }
}

function duplicateHint(service: Service) {
  // The API only supports duplicating a VERSION (POST /versions/{version}/duplicate),
  // not a whole service — there's no service-level duplicate endpoint. Send the
  // operator into the editor's version switcher instead of inventing one.
  toast.show('Duplicate a version from inside the service — opening it now.')
  router.push(`/services/${service.id}`)
}

function deleteHint() {
  // The Catalog Admin API deliberately registers only index/store/show/update
  // for services — no destroy route exists. Services are deactivated via
  // status, never deleted, presumably because orders/webhook_deliveries keep
  // historical FKs to them. Flagged in the PR rather than inventing one.
  toast.show('Services can’t be deleted — pause it instead, or ask for a destroy endpoint to be added.')
}
</script>

<template>
  <div style="display: flex; flex-direction: column; height: 100vh; overflow: hidden">
    <header class="flex flex-none items-center justify-between border-b border-[#ECECEE] bg-white px-[34px] py-[22px]">
      <div>
        <div class="mb-[5px] text-[11.5px] font-semibold uppercase tracking-[.06em] text-[#8A8F98]">AIHD Lab · Admin</div>
        <h1 class="m-0 text-[34px] font-extrabold tracking-[-.03em]">Services</h1>
      </div>
      <div class="flex items-center gap-2.5">
        <div class="relative">
          <svg class="pointer-events-none absolute left-[14px] top-1/2 -translate-y-1/2" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#8A8F98" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
          <input
            v-model="query"
            placeholder="Search services…"
            class="h-[50px] w-[340px] rounded-[14px] border border-[#EBEBED] bg-white pl-[46px] pr-[18px] text-[14.5px] text-[#19191A] focus:border-[#5639E5]"
          >
        </div>
        <button
          class="inline-flex h-11 items-center gap-2 rounded-full border-0 bg-[#5639E5] px-[22px] text-sm font-semibold text-white shadow-[0_2px_10px_rgba(86,57,229,.28)]"
          @click="showNewService = true"
        >
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
          New service
        </button>
      </div>
    </header>

    <div class="flex-1 overflow-auto px-[34px] pb-[60px] pt-7">
      <div class="mb-[26px] grid grid-cols-3 gap-4">
        <div class="rounded-2xl border border-[#ECECEE] bg-white px-[22px] py-[18px] shadow-[0_1px_2px_rgba(133,151,171,.06)]">
          <div class="mb-2 text-[11px] font-semibold uppercase tracking-[.06em] text-[#8A8F98]">Total services</div>
          <div class="text-[30px] font-extrabold tracking-[-.02em]">{{ catalog.serviceCount }}</div>
        </div>
        <div class="rounded-2xl border border-[#ECECEE] bg-white px-[22px] py-[18px] shadow-[0_1px_2px_rgba(133,151,171,.06)]">
          <div class="mb-2 text-[11px] font-semibold uppercase tracking-[.06em] text-[#8A8F98]">Active</div>
          <div class="text-[30px] font-extrabold tracking-[-.02em] text-[#168A40]">{{ catalog.activeCount }}</div>
        </div>
        <div class="rounded-2xl border border-[#ECECEE] bg-white px-[22px] py-[18px] shadow-[0_1px_2px_rgba(133,151,171,.06)]">
          <div class="mb-2 text-[11px] font-semibold uppercase tracking-[.06em] text-[#8A8F98]">Inactive / draft</div>
          <div class="text-[30px] font-extrabold tracking-[-.02em] text-[#8A8F98]">{{ catalog.inactiveCount }}</div>
        </div>
      </div>

      <div class="grid gap-5" style="grid-template-columns: repeat(auto-fill, minmax(320px, 1fr))">
        <div v-for="s in filtered" :key="s.id" class="flex flex-col overflow-hidden rounded-[20px] border border-[#ECECEE] bg-white shadow-[0_1px_2px_rgba(133,151,171,.05)]">
          <div class="flex items-start gap-[13px] px-5 pt-5">
            <div class="flex h-12 w-12 flex-none items-center justify-center rounded-[14px]" :style="{ background: iconColors(s).bg, color: iconColors(s).fg }">
              <span class="text-[19px] font-extrabold">{{ s.name.charAt(0).toUpperCase() }}</span>
            </div>
            <div class="min-w-0 flex-1">
              <div class="mb-[5px] overflow-hidden text-ellipsis whitespace-nowrap text-base font-bold tracking-[-.01em]">{{ s.name }}</div>
              <div class="flex min-w-0 items-center gap-1.5">
                <span class="min-w-0 flex-none overflow-hidden text-ellipsis whitespace-nowrap rounded-md bg-[#F0EDFE] px-2 py-0.5 font-mono text-[10.5px] text-[#5639E5]">{{ s.slug }}</span>
                <span v-if="s.kind === 'external'" class="flex-none whitespace-nowrap rounded-md bg-[#E5F4FF] px-2 py-0.5 text-[10px] font-bold text-[#0073C6]">External</span>
              </div>
            </div>
            <button
              class="inline-flex flex-none items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-bold"
              :style="STATUS_STYLE[s.status]"
              @click="toggleStatus(s)"
            >
              <span class="h-1.5 w-1.5 rounded-full" style="background: currentColor" />{{ STATUS_LABEL[s.status] }}
            </button>
          </div>

          <div class="min-h-[44px] flex-1 px-5 pb-4 pt-3.5 text-[13px] leading-[1.6] text-[#6B7280]" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden">{{ s.description }}</div>

          <div class="flex flex-wrap items-center gap-2 px-5 pb-4">
            <template v-if="s.kind === 'internal'">
              <span class="inline-flex items-center gap-1 rounded-full bg-[#F4F5F7] px-[11px] py-[5px] text-[11.5px] font-semibold text-[#4B4C4D]">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#8A8F98" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2 8l10 5 10-5-10-5Z" /><path d="m2 16 10 5 10-5M2 12l10 5 10-5" /></svg>
                {{ s.versions_count ?? 0 }} versions
              </span>
              <span class="inline-flex items-center gap-1 rounded-full bg-[#F4F5F7] px-[11px] py-[5px] text-[11.5px] font-semibold text-[#4B4C4D]">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#8A8F98" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /></svg>
                {{ s.item_count ?? 0 }} items
              </span>
            </template>
            <span v-else class="inline-flex items-center gap-1 rounded-full bg-[#E5F4FF] px-[11px] py-[5px] text-[11.5px] font-semibold text-[#0073C6]">External link</span>
            <span class="inline-flex items-center gap-1 rounded-full bg-[#F5EEFF] px-[11px] py-[5px] text-[11.5px] font-bold text-[#7E2EE5]">
              <span class="flex h-3.5 w-3.5 items-center justify-center rounded-full text-[8px] text-white" style="background: linear-gradient(135deg, #9747ff, #5639e5)">c</span>
              {{ s.current_version?.coin_cost ?? 0 }} credits
            </span>
          </div>

          <div class="flex items-center gap-2 border-t border-[#F1F2F5] bg-[#FAFBFC] px-4 py-3.5">
            <button class="inline-flex h-[38px] flex-1 items-center justify-center gap-1.5 rounded-full border-0 bg-[#5639E5] text-[13px] font-semibold text-white" @click="openService(s)">
              Open
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
            </button>
            <button title="Duplicate" class="flex h-[38px] w-[38px] items-center justify-center rounded-[10px] border border-[#E1E4E8] bg-white text-[#4B4C4D]" @click="duplicateHint(s)">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg>
            </button>
            <button title="Delete" class="flex h-[38px] w-[38px] items-center justify-center rounded-[10px] border border-[#F7C2CC] bg-white text-[#D70D3E]" @click="deleteHint">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" /></svg>
            </button>
          </div>
        </div>

        <div v-if="filtered.length === 0" class="col-span-full p-14 text-center text-sm text-[#8A8F98]">No services found.</div>
      </div>
    </div>

    <NewServiceModal v-if="showNewService" @close="showNewService = false" />
  </div>
</template>
