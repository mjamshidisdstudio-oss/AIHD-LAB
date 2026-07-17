<script setup lang="ts">
// design-reference/service-admin.dc.html lines 772-795.
import { useServiceDetailStore } from '~/stores/serviceDetail'
import { useToastStore } from '~/stores/toast'
import type { ServiceVersion } from '~/types/api'

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

// A version's display name falls back to "v{version_no}" until an operator
// renames it via service_versions.label (see PR: schema-gap decisions).
function label(v: ServiceVersion) {
  return v.label ?? `v${v.version_no}`
}

// Inline rename — the design draws this as a pill turning into an input
// (border highlighted, save-checkmark button) rather than a modal.
const renamingVersionId = ref<string | null>(null)
const renameDraft = ref('')

function startRename(v: ServiceVersion) {
  renamingVersionId.value = v.id
  renameDraft.value = label(v)
}
function cancelRename() {
  renamingVersionId.value = null
  // Blur so the subsequent, inevitable blur event (focus is still on the
  // now-hidden input) doesn't re-fire saveRename with the discarded draft.
  ;(document.activeElement as HTMLElement | null)?.blur()
}
async function saveRename(versionId: string) {
  if (renamingVersionId.value !== versionId) return
  const value = renameDraft.value.trim()
  try {
    // Bypasses ensureEditable() server-side -- a label is bookkeeping
    // metadata, not frozen configuration, so this must succeed for a
    // published/retired version too.
    await api.patch(`/admin/versions/${versionId}/label`, { label: value || null })
    await detail.reloadVersion(versionId)
    toast.show('Version renamed.')
  } catch {
    toast.show('Could not rename this version.')
  } finally {
    renamingVersionId.value = null
  }
}
</script>

<template>
  <div class="flex flex-none items-center gap-2.5 overflow-x-auto border-b border-[#ECDDFF] bg-[#FBFAFF] px-7 py-[11px]">
    <span class="whitespace-nowrap text-[11.5px] font-bold text-[#7E2EE5]">Versions</span>
    <div v-for="v in detail.sortedVersions" :key="v.id" class="inline-flex items-center gap-[3px]">
      <template v-if="renamingVersionId === v.id">
        <input
          v-model="renameDraft"
          placeholder="Version name"
          class="h-8 w-[130px] rounded-full border-[1.5px] border-[#5639E5] bg-white px-3 text-[12.5px] font-semibold text-[#4628C9]"
          @keyup.enter="saveRename(v.id)"
          @keyup.esc="cancelRename"
          @blur="saveRename(v.id)"
        >
        <button title="Save" class="flex h-7 w-7 items-center justify-center rounded-full text-[#168A40] hover:bg-[#E8F8EE]" @mousedown.prevent="saveRename(v.id)">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7" /></svg>
        </button>
      </template>
      <template v-else>
        <button
          class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-1.5 text-[12.5px] font-semibold"
          :class="v.id === detail.selectedVersionId ? 'bg-[#5639E5] text-white' : 'bg-white text-[#4B4C4D] border border-[#E1D9F7]'"
          @click="pick(v.id)"
        >
          <span class="h-1.5 w-1.5 rounded-full" :style="{ background: v.id === detail.selectedVersionId ? '#fff' : STATUS_DOT[v.status] }" />
          {{ label(v) }} · {{ v.status }}
        </button>
        <button title="Rename version" class="flex h-7 w-7 items-center justify-center rounded-full text-[#7E2EE5] hover:bg-[#F0EDFE]" @click="startRename(v)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" /></svg>
        </button>
        <button title="Duplicate to a new draft" class="flex h-7 w-7 items-center justify-center rounded-full text-[#7E2EE5] hover:bg-[#F0EDFE]" @click="clone(v.id)">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg>
        </button>
      </template>
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
