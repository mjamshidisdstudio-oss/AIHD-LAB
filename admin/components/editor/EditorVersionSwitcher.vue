<script setup lang="ts">
// design-reference/service-admin.dc.html lines 772-795.
import { useServiceDetailStore } from '~/stores/serviceDetail'
import { useToastStore } from '~/stores/toast'

const detail = useServiceDetailStore()
const toast = useToastStore()
const api = useApi()

const STATUS_DOT: Record<string, string> = {
  draft: '#8A8F98',
  published: '#168A40',
  retired: '#D70D3E',
}

async function pick(versionId: string) {
  detail.selectVersion(versionId)
}

async function addVersion() {
  if (!detail.service) return
  try {
    const res = await api.post<{ data: { id: string } }>(`/admin/services/${detail.service.id}/versions`, {})
    await detail.reloadVersions()
    detail.selectVersion(res.data.id)
    toast.show('New draft version created.')
  } catch {
    toast.show('Could not create a new version.')
  }
}

async function clone(versionId: string) {
  try {
    const res = await api.post<{ data: { id: string } }>(`/admin/versions/${versionId}/duplicate`, {})
    await detail.reloadVersions()
    detail.selectVersion(res.data.id)
    toast.show('Version duplicated as a new draft.')
  } catch {
    toast.show('Could not duplicate this version.')
  }
}

async function publish() {
  if (!detail.selectedVersion) return
  try {
    await api.post(`/admin/versions/${detail.selectedVersion.id}/publish`, {})
    await Promise.all([detail.reloadVersions(), detail.reloadService()])
    toast.show('Version published. The previous version was retired.')
  } catch (e: any) {
    toast.show(e?.data?.message ?? 'Could not publish this version.')
  }
}

// The design's version tabs support inline rename — there is no schema
// column for a version's display name (only version_no), so instead of
// inventing one, each pill just shows "v{version_no}". Flagged in the PR.
function label(versionNo: number) {
  return `v${versionNo}`
}
</script>

<template>
  <div class="flex flex-none items-center gap-2.5 overflow-x-auto border-b border-[#ECDDFF] bg-[#FBFAFF] px-7 py-[11px]">
    <span class="whitespace-nowrap text-[11.5px] font-bold text-[#7E2EE5]">Versions</span>
    <div v-for="v in detail.sortedVersions" :key="v.id" class="inline-flex items-center gap-[3px]">
      <button
        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-1.5 text-[12.5px] font-semibold"
        :class="v.id === detail.selectedVersionId ? 'bg-[#5639E5] text-white' : 'bg-white text-[#4B4C4D] border border-[#E1D9F7]'"
        @click="pick(v.id)"
      >
        <span class="h-1.5 w-1.5 rounded-full" :style="{ background: v.id === detail.selectedVersionId ? '#fff' : STATUS_DOT[v.status] }" />
        {{ label(v.version_no) }} · {{ v.status }}
      </button>
      <button title="Duplicate to a new draft" class="flex h-7 w-7 items-center justify-center rounded-full text-[#7E2EE5] hover:bg-[#F0EDFE]" @click="clone(v.id)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg>
      </button>
    </div>
    <button class="inline-flex items-center gap-[5px] whitespace-nowrap rounded-full border-[1.5px] border-dashed border-[#ECDDFF] bg-white px-3.5 py-[7px] text-[12.5px] font-semibold text-[#7E2EE5]" @click="addVersion">
      + New version
    </button>
    <div class="ml-auto flex items-center gap-2.5">
      <span v-if="detail.selectedVersion?.status === 'published'" class="whitespace-nowrap text-[11.5px] font-semibold text-[#168A40]">✓ Published</span>
      <span v-else-if="detail.selectedVersion?.status === 'retired'" class="whitespace-nowrap text-[11.5px] font-semibold text-[#D70D3E]">Retired</span>
      <button v-else-if="detail.selectedVersion?.status === 'draft'" class="h-[34px] whitespace-nowrap rounded-full border-0 bg-[#5639E5] px-4 text-[12.5px] font-semibold text-white" @click="publish">
        Publish this version
      </button>
    </div>
  </div>
</template>
