<script setup lang="ts">
// design-reference/service-admin.dc.html lines 1218-1281 (Outputs tab).
// The design's output-type chips include "JSON" and, for image outputs, a
// resolution/quality chip (1K/2K/4K) — neither exists in ServiceOutputType
// (text|image|video only) or the service_outputs schema (no quality/
// resolution column). Dropped rather than invented; flagged in the PR.
import { useServiceDetailStore } from '~/stores/serviceDetail'
import { useToastStore } from '~/stores/toast'
import type { ServiceOutputType } from '~/types/api'

const detail = useServiceDetailStore()
const toast = useToastStore()
const api = useApi()

const version = computed(() => detail.selectedVersion)
const isDraft = computed(() => version.value?.status === 'draft')
const outputs = computed(() => [...(version.value?.outputs ?? [])].sort((a, b) => a.result_number - b.result_number))
const waitingTexts = computed(() => [...(version.value?.waiting_texts ?? [])].sort((a, b) => a.sort_order - b.sort_order))

watchEffect(async () => {
  if (version.value) await detail.ensureVersionDetailLoaded(version.value.id)
})

const TYPES: ServiceOutputType[] = ['text', 'image', 'video']

async function addOutput() {
  if (!version.value) return
  const nextNumber = (outputs.value[outputs.value.length - 1]?.result_number ?? 0) + 1
  try {
    await api.post(`/admin/versions/${version.value.id}/outputs`, { result_number: nextNumber, type: 'image' })
    await detail.reloadVersion(version.value.id)
  } catch (e: any) {
    toast.show(e?.data?.message ?? 'Could not add output.')
  }
}

async function setOutputType(outputId: string, type: ServiceOutputType) {
  try {
    await api.patch(`/admin/outputs/${outputId}`, { type })
    await detail.reloadVersion(version.value!.id)
  } catch (e: any) {
    toast.show(e?.data?.message ?? 'Could not update output.')
  }
}

async function removeOutput(outputId: string) {
  try {
    await api.del(`/admin/outputs/${outputId}`)
    await detail.reloadVersion(version.value!.id)
  } catch (e: any) {
    toast.show(e?.data?.message ?? 'Could not remove output.')
  }
}

const newWaitingText = ref('')

async function addWaitingText() {
  if (!version.value || !newWaitingText.value.trim()) return
  try {
    await api.post(`/admin/versions/${version.value.id}/waiting-texts`, {
      text: newWaitingText.value.trim(),
      sort_order: waitingTexts.value.length,
    })
    await detail.reloadVersion(version.value.id)
    newWaitingText.value = ''
  } catch (e: any) {
    toast.show(e?.data?.message ?? 'Could not add waiting text.')
  }
}

async function removeWaitingText(id: string) {
  try {
    await api.del(`/admin/waiting-texts/${id}`)
    await detail.reloadVersion(version.value!.id)
  } catch (e: any) {
    toast.show(e?.data?.message ?? 'Could not remove waiting text.')
  }
}
</script>

<template>
  <div class="flex-1 overflow-auto bg-[#F6F7F9] p-8">
    <div class="mx-auto flex max-w-[720px] flex-col gap-5">
      <div v-if="!isDraft" class="flex items-center gap-2 rounded-xl bg-[#FFF7E5] px-4 py-3 text-[12.5px] font-semibold text-[#966B0A]">
        This version is {{ version?.status }} and frozen — outputs can't be edited.
      </div>

      <div class="rounded-[22px] border border-[#ECECEE] bg-white p-7 shadow-[0_1px_2px_rgba(133,151,171,.05)]" :class="{ 'pointer-events-none opacity-50': !isDraft }">
        <div class="mb-1 flex items-center justify-between">
          <div class="text-base font-bold">Outputs — v{{ version?.version_no }}</div>
          <button class="rounded-full bg-[#F0EDFE] px-3.5 py-1.5 text-[12.5px] font-semibold text-[#5639E5]" @click="addOutput">+ Add output</button>
        </div>
        <div class="mb-4 text-[13px] text-[#7D7E80]">What this version produces, in order.</div>

        <div class="flex flex-col gap-2">
          <div v-for="o in outputs" :key="o.id" class="flex items-center gap-3 rounded-xl border border-[#ECECEE] px-4 py-3">
            <span class="font-mono text-[12px] font-bold text-[#8A8F98]">#{{ o.result_number }}</span>
            <div class="flex flex-1 gap-1.5">
              <button v-for="t in TYPES" :key="t" class="rounded-full px-3 py-1 text-[12px] font-semibold capitalize" :class="o.type === t ? 'bg-[#5639E5] text-white' : 'bg-[#F4F5F7] text-[#4B4C4D]'" @click="setOutputType(o.id, t)">{{ t }}</button>
            </div>
            <button title="Remove" class="text-[#D70D3E]" @click="removeOutput(o.id)">✕</button>
          </div>
          <div v-if="outputs.length === 0" class="rounded-xl border border-dashed border-[#E1E4E8] p-4 text-center text-[12px] text-[#8A8F98]">No outputs yet.</div>
        </div>
      </div>

      <div class="rounded-[22px] border border-[#ECECEE] bg-white p-7 shadow-[0_1px_2px_rgba(133,151,171,.05)]" :class="{ 'pointer-events-none opacity-50': !isDraft }">
        <div class="mb-1 text-base font-bold">Waiting messages</div>
        <div class="mb-4 text-[13px] text-[#7D7E80]">Rotated while an order is processing.</div>
        <div class="flex flex-col gap-2">
          <div v-for="w in waitingTexts" :key="w.id" class="flex items-center justify-between rounded-xl border border-[#ECECEE] px-4 py-2.5 text-[13px]">
            {{ w.text }}
            <button title="Remove" class="text-[#D70D3E]" @click="removeWaitingText(w.id)">✕</button>
          </div>
        </div>
        <div class="mt-3 flex gap-2">
          <input v-model="newWaitingText" placeholder="e.g. Dreaming up seasonal styles…" class="h-10 flex-1 rounded-lg border border-[#DCE0E7] bg-[#FAFBFD] px-3 text-[13px]" @keyup.enter="addWaitingText">
          <button class="rounded-lg bg-[#F0EDFE] px-3.5 text-[13px] font-semibold text-[#5639E5]" @click="addWaitingText">Add</button>
        </div>
      </div>
    </div>
  </div>
</template>
