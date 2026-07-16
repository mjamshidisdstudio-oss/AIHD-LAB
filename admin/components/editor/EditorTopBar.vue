<script setup lang="ts">
// design-reference/service-admin.dc.html lines 673-698.
import { useServiceDetailStore } from '~/stores/serviceDetail'
import { useToastStore } from '~/stores/toast'

const detail = useServiceDetailStore()
const toast = useToastStore()
const router = useRouter()

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

async function toggleActive() {
  if (!detail.service) return
  const next = detail.service.status === 'active' ? 'paused' : 'active'
  try {
    await useApi().patch(`/admin/services/${detail.service.id}`, { status: next })
    await detail.reloadService()
  } catch {
    toast.show('Could not update status.')
  }
}

function openFullPreview() {
  toast.show('Full preview — use the Builder tab’s live preview panel.')
}
</script>

<template>
  <header class="flex flex-none items-center justify-between border-b border-[#EBEDF0] bg-white px-7 py-3.5">
    <div class="flex min-w-0 items-center gap-3.5">
      <button title="Back" class="flex h-[38px] w-[38px] flex-none items-center justify-center rounded-[10px] border border-[#D4D9E3] bg-white text-[#4B4C4D]" @click="router.push('/')">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6" /></svg>
      </button>
      <div class="min-w-0">
        <div class="mb-0.5 text-[11.5px] font-medium text-[#7D7E80]">Services / Edit</div>
        <div class="flex items-center gap-2">
          <div class="max-w-[280px] truncate text-lg font-bold tracking-[-.01em]">{{ detail.service?.name }}</div>
          <span class="whitespace-nowrap rounded-md bg-[#F0EDFE] px-2 py-0.5 font-mono text-[11.5px] text-[#5639E5]">{{ detail.service?.slug }}</span>
        </div>
      </div>
    </div>
    <div class="flex items-center gap-3">
      <button v-if="detail.service" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-1.5 text-[13px] font-bold" :style="STATUS_STYLE[detail.service.status]" @click="toggleActive">
        <span class="h-[7px] w-[7px] rounded-full" style="background: currentColor" />{{ STATUS_LABEL[detail.service.status] }}
      </button>
      <div class="h-[26px] w-px bg-[#EBEDF0]" />
      <button class="inline-flex h-10 items-center gap-[7px] rounded-full border border-[#D4D9E3] bg-white px-4 text-[13px] font-semibold text-[#4B4C4D]" @click="openFullPreview">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3" /></svg>
        Full preview
      </button>
    </div>
  </header>
</template>
