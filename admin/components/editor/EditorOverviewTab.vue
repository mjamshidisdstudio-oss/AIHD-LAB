<script setup lang="ts">
// design-reference/service-admin.dc.html lines 797-980 (Overview/Info tab).
// Simplified relative to the design where the schema has no backing column:
// no long_description/publish_date/gallery/before-after fields exist on
// `services` (only `description`, one field) — flagged in the PR rather than
// inventing storage for them. Pricing (coin_cost) lives on the VERSION, not
// the service, so it's edited in the Integration tab instead of here.
import { useServiceDetailStore } from '~/stores/serviceDetail'
import { useToastStore } from '~/stores/toast'

const detail = useServiceDetailStore()
const toast = useToastStore()
const api = useApi()

const name = ref('')
const category = ref('')
const description = ref('')
const externalUrl = ref('')
const imageUrl = ref('')
const saving = ref(false)

watchEffect(() => {
  if (!detail.service) return
  name.value = detail.service.name
  category.value = detail.service.category
  description.value = detail.service.description ?? ''
  externalUrl.value = detail.service.external_url ?? ''
  imageUrl.value = detail.service.image_url ?? ''
})

const setupSteps = computed(() => {
  const s = detail.service
  const v = detail.selectedVersion
  return [
    { label: 'Name & category', done: !!s?.name && !!s?.category },
    { label: 'Description', done: !!s?.description },
    { label: 'Secret configured', done: !!s?.has_secret },
    { label: 'At least one input', done: (v?.inputs?.length ?? 0) > 0 },
    { label: 'At least one output', done: (v?.outputs?.length ?? 0) > 0 },
    { label: 'Version published', done: s?.current_version_id !== null },
  ]
})
const setupDoneCount = computed(() => setupSteps.value.filter((s) => s.done).length)
const setupPct = computed(() => Math.round((setupDoneCount.value / setupSteps.value.length) * 100))

async function save() {
  if (!detail.service) return
  saving.value = true
  try {
    await api.patch(`/admin/services/${detail.service.id}`, {
      name: name.value,
      category: category.value,
      description: description.value,
      image_url: imageUrl.value || null,
      ...(detail.service.kind === 'external' ? { external_url: externalUrl.value } : {}),
    })
    await detail.reloadService()
    toast.show('Saved.')
  } catch {
    toast.show('Could not save changes.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="flex-1 overflow-auto bg-[#F6F7F9] p-8">
    <div class="mx-auto flex max-w-[720px] flex-col gap-5">
      <!-- setup checklist -->
      <div class="rounded-[22px] border border-[#ECECEE] bg-white p-6 shadow-[0_1px_2px_rgba(133,151,171,.05)]">
        <div class="mb-3.5 flex items-center justify-between gap-3">
          <div>
            <div class="text-[15px] font-bold">Set up this service</div>
            <div class="mt-0.5 text-xs text-[#7D7E80]">Work through each step.</div>
          </div>
          <span class="text-xs font-bold text-[#5639E5]">{{ setupDoneCount }}/{{ setupSteps.length }}</span>
        </div>
        <div class="mb-4 h-[7px] overflow-hidden rounded-full bg-[#F0EDFB]">
          <div class="h-full rounded-full bg-[#5639E5]" :style="{ width: `${setupPct}%` }" />
        </div>
        <div class="grid grid-cols-3 gap-2.5">
          <div v-for="(step, i) in setupSteps" :key="i" class="flex items-center gap-2 rounded-xl border border-[#ECECEE] px-2.5 py-2 text-[12px] font-semibold" :class="step.done ? 'text-[#168A40]' : 'text-[#4B4C4D]'">
            <span class="flex h-5 w-5 flex-none items-center justify-center rounded-full text-[10px] font-bold" :class="step.done ? 'bg-[#E8F8EE] text-[#168A40]' : 'bg-[#F4F5F7] text-[#8A8F98]'">
              <svg v-if="step.done" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
              <template v-else>{{ i + 1 }}</template>
            </span>
            {{ step.label }}
          </div>
        </div>
      </div>

      <!-- service type (read-only: kind is immutable after creation) -->
      <div class="rounded-[22px] border border-[#ECECEE] bg-white p-7 shadow-[0_1px_2px_rgba(133,151,171,.05)]">
        <div class="mb-1 text-base font-bold">Service type</div>
        <div class="mb-4 text-[13px] text-[#7D7E80]">Set at creation; not editable afterward.</div>
        <div class="inline-flex items-center gap-2 rounded-xl border border-[#5639E5] bg-[#F0EDFE] px-4 py-2.5 text-sm font-bold text-[#5639E5]">
          {{ detail.service?.kind === 'external' ? 'External link' : 'Built-in service' }}
        </div>
        <div v-if="detail.service?.kind === 'external'" class="mt-4">
          <label class="mb-2 block text-[13px] font-semibold">External service URL</label>
          <input v-model="externalUrl" class="h-[46px] w-full rounded-xl border border-[#DCE0E7] bg-[#FAFBFD] px-4 font-mono text-[13px] text-[#5639E5]" placeholder="https://…">
        </div>
      </div>

      <!-- basic info -->
      <div class="rounded-[22px] border border-[#ECECEE] bg-white p-7 shadow-[0_1px_2px_rgba(133,151,171,.05)]">
        <div class="mb-1 text-base font-bold">Basic info</div>
        <div class="mb-5 text-[13px] text-[#7D7E80]">Shown to the end user.</div>
        <div class="mb-5 flex gap-3.5">
          <div class="min-w-0 flex-1">
            <label class="mb-2 block text-[13px] font-semibold">Service name</label>
            <input v-model="name" class="h-[46px] w-full rounded-xl border border-[#DCE0E7] bg-[#FAFBFD] px-4 text-sm">
          </div>
          <div class="min-w-0 flex-1">
            <label class="mb-2 block text-[13px] font-semibold">Service slug <span class="font-normal text-[#969799]">· immutable</span></label>
            <input :value="detail.service?.slug" disabled class="h-[46px] w-full rounded-xl border border-[#DCE0E7] bg-[#F4F5F7] px-4 font-mono text-[13px] text-[#8A8F98]">
          </div>
        </div>
        <label class="mb-2 block text-[13px] font-semibold">Category</label>
        <input v-model="category" class="mb-5 h-[46px] w-full rounded-xl border border-[#DCE0E7] bg-[#FAFBFD] px-4 text-sm">

        <label class="mb-2 block text-[13px] font-semibold">Description <span class="font-normal text-[#969799]">· shown on the service card and detail page</span></label>
        <textarea v-model="description" class="mb-1 min-h-[100px] w-full resize-y rounded-xl border border-[#DCE0E7] bg-[#FAFBFD] px-4 py-3.5 text-sm leading-[1.7]" />

        <label class="mb-2 mt-5 block text-[13px] font-semibold">Cover image URL</label>
        <input v-model="imageUrl" class="h-[46px] w-full rounded-xl border border-[#DCE0E7] bg-[#FAFBFD] px-4 text-sm" placeholder="https://…">

        <button class="mt-6 h-11 rounded-full bg-[#5639E5] px-6 text-[13.5px] font-semibold text-white disabled:opacity-60" :disabled="saving" @click="save">
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </div>
    </div>
  </div>
</template>
