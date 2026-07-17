<script setup lang="ts">
// design-reference/service-admin.dc.html lines 1495-2077 (Builder tab) — the
// design models this as a nested tree (branches[].children[] for groups,
// flat children[] for bundles). The real schema is flat-relational: there is
// no container FK for bundle/conditional_group at all, only the SAME
// depends_on_input_id/depends_on_value pair every input type already uses
// for visibility gating. So containment here is expressed the identical way:
// a child's depends_on_input_id points at the bundle/group's id, and (for a
// conditional_group's branches) depends_on_value carries the branch key.
// Branch labels have no schema column either, so they're kept in the
// group's own `config.branches` JSON (an existing free-form column) rather
// than inventing a table. Flagged in the PR as an interpretation, not a
// discovered fact.
import { useServiceDetailStore } from '~/stores/serviceDetail'
import { useToastStore } from '~/stores/toast'
import type { ServiceInput, ServiceInputType } from '~/types/api'

const detail = useServiceDetailStore()
const toast = useToastStore()
const api = useApi()

const TYPE_LABEL: Record<ServiceInputType, string> = {
  text: 'Text',
  image: 'Image',
  video: 'Video',
  select: 'Select list',
  boolean: 'Toggle',
  bundle: 'Input bundle',
  conditional_group: 'Conditional group',
}

const version = computed(() => detail.selectedVersion)
const isDraft = computed(() => version.value?.status === 'draft')
const inputs = computed(() => version.value?.inputs ?? [])
const selectedInputId = ref<string | null>(null)
const selectedInput = computed(() => inputs.value.find((i) => i.id === selectedInputId.value) ?? null)

watchEffect(async () => {
  if (version.value) await detail.ensureVersionDetailLoaded(version.value.id)
})

async function duplicateToDraft() {
  if (!version.value) return
  const res = await api.post<{ data: { id: string } }>(`/admin/versions/${version.value.id}/duplicate`, {})
  await detail.reloadVersions()
  detail.selectVersion(res.data.id)
  toast.show('Duplicated to a new draft — edit it there.')
}

async function addInput(type: ServiceInputType) {
  if (!version.value) return
  const slug = `${type}_${Date.now().toString(36)}`
  try {
    const res = await api.post<{ data: ServiceInput }>(`/admin/versions/${version.value.id}/inputs`, {
      slug,
      title: TYPE_LABEL[type],
      type,
      sort_order: inputs.value.length,
    })
    await detail.reloadVersion(version.value.id)
    selectedInputId.value = res.data.id
  } catch (e: any) {
    toast.show(e?.data?.message ?? 'Could not add input.')
  }
}

async function deleteInput(input: ServiceInput) {
  if (!version.value) return
  try {
    await api.del(`/admin/inputs/${input.id}`)
    await detail.reloadVersion(version.value.id)
    if (selectedInputId.value === input.id) selectedInputId.value = null
  } catch (e: any) {
    toast.show(e?.data?.message ?? 'Could not delete input.')
  }
}

// ---- selected input editor ----
const form = reactive({
  title: '',
  slug: '',
  required: false,
  multi_select: false,
  searchable: false,
  depends_on_input_id: '' as string,
  depends_on_value: '',
})
const errors = ref<Record<string, string[]>>({})

// Resyncs the form from the selected input only when the SELECTION changes
// (not on every version reload) — an option add/remove reloads the whole
// version from the server and would otherwise clobber an in-progress,
// not-yet-saved title/slug/dependency edit sitting in `form`.
watch(selectedInputId, () => {
  const i = selectedInput.value
  if (!i) return
  form.title = i.title
  form.slug = i.slug
  form.required = i.required
  form.multi_select = i.multi_select
  form.searchable = i.searchable
  form.depends_on_input_id = i.depends_on_input_id ?? ''
  form.depends_on_value = i.depends_on_value ?? ''
}, { immediate: true })

const dependencyCandidates = computed(() => inputs.value.filter((i) => i.id !== selectedInputId.value))

async function saveInput() {
  if (!selectedInput.value || !version.value) return
  errors.value = {}
  try {
    await api.patch(`/admin/inputs/${selectedInput.value.id}`, {
      title: form.title,
      slug: form.slug,
      required: form.required,
      multi_select: form.multi_select,
      searchable: form.searchable,
      depends_on_input_id: form.depends_on_input_id || null,
      depends_on_value: form.depends_on_input_id ? form.depends_on_value : null,
    })
    await detail.reloadVersion(version.value.id)
    toast.show('Input saved.')
  } catch (e: any) {
    errors.value = e?.data?.errors ?? {}
    toast.show(e?.data?.message ?? 'Could not save input.')
  }
}

// ---- options (select-type inputs only) ----
const newOptionLabel = ref('')

async function addOption() {
  if (!selectedInput.value || !newOptionLabel.value.trim()) return
  const label = newOptionLabel.value.trim()
  const slug = label.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '')
  try {
    await api.post(`/admin/inputs/${selectedInput.value.id}/options`, {
      label,
      slug,
      sort_order: selectedInput.value.options?.length ?? 0,
    })
    await detail.reloadVersion(version.value!.id)
    newOptionLabel.value = ''
  } catch (e: any) {
    toast.show(e?.data?.message ?? 'Could not add option.')
  }
}

async function deleteOption(optionId: string) {
  try {
    await api.del(`/admin/options/${optionId}`)
    await detail.reloadVersion(version.value!.id)
  } catch (e: any) {
    toast.show(e?.data?.message ?? 'Could not delete option.')
  }
}
</script>

<template>
  <div class="flex-1 overflow-hidden bg-[#F6F7F9]">
    <div v-if="!isDraft" class="mx-8 mt-8 flex items-center gap-2 rounded-xl bg-[#FFF7E5] px-4 py-3 text-[12.5px] font-semibold text-[#966B0A]">
      This version is {{ version?.status }} and frozen — inputs can't be edited. Duplicate it to a new draft first.
      <button class="ml-auto rounded-full bg-[#966B0A] px-3 py-1.5 text-white" @click="duplicateToDraft">Duplicate to draft</button>
    </div>

    <div class="flex h-full gap-5 overflow-hidden p-8" :class="{ 'pointer-events-none opacity-50': !isDraft }">
      <!-- column 1: input list -->
      <div class="flex w-[260px] flex-none flex-col gap-3 overflow-auto">
        <div class="rounded-2xl border border-[#ECECEE] bg-white p-3">
          <div class="mb-2 px-1 text-[11px] font-bold uppercase tracking-wide text-[#8A8F98]">Add input</div>
          <div class="grid grid-cols-2 gap-1.5">
            <button v-for="(label, type) in TYPE_LABEL" :key="type" class="rounded-lg border border-[#E1E4E8] px-2 py-2 text-[11.5px] font-semibold text-[#4B4C4D] hover:bg-[#F6F7F9]" @click="addInput(type as ServiceInputType)">
              {{ label }}
            </button>
          </div>
        </div>
        <div v-for="input in inputs" :key="input.id" class="flex cursor-pointer items-center gap-2 rounded-xl border bg-white px-3 py-2.5" :class="input.id === selectedInputId ? 'border-[#5639E5]' : 'border-[#ECECEE]'" @click="selectedInputId = input.id">
          <div class="min-w-0 flex-1">
            <div class="truncate text-[13px] font-semibold">{{ input.title }}</div>
            <div class="text-[11px] text-[#8A8F98]">{{ TYPE_LABEL[input.type] }}<span v-if="input.depends_on_input_id"> · gated</span></div>
          </div>
          <button title="Delete" class="flex h-6 w-6 flex-none items-center justify-center rounded-full text-[#D70D3E] hover:bg-[#FDECEF]" @click.stop="deleteInput(input)">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" /></svg>
          </button>
        </div>
        <div v-if="inputs.length === 0" class="rounded-xl border border-dashed border-[#E1E4E8] p-4 text-center text-[12px] text-[#8A8F98]">No inputs yet.</div>
      </div>

      <!-- column 2: selected input editor -->
      <div class="flex-1 overflow-auto rounded-2xl border border-[#ECECEE] bg-white p-6">
        <template v-if="selectedInput">
          <div class="mb-4 text-sm font-bold">{{ TYPE_LABEL[selectedInput.type] }}</div>
          <div class="grid grid-cols-2 gap-3.5">
            <label class="flex flex-col gap-1.5">
              <span class="text-[12.5px] font-semibold">Label</span>
              <input v-model="form.title" class="h-10 rounded-lg border border-[#DCE0E7] bg-[#FAFBFD] px-3 text-sm">
            </label>
            <label class="flex flex-col gap-1.5">
              <span class="text-[12.5px] font-semibold">Slug</span>
              <input v-model="form.slug" class="h-10 rounded-lg border border-[#DCE0E7] bg-[#FAFBFD] px-3 font-mono text-[13px]">
              <span v-if="errors.slug" class="text-[11px] font-medium text-[#D70D3E]">{{ errors.slug[0] }}</span>
            </label>
          </div>

          <label class="mt-4 flex items-center gap-2 text-[12.5px] font-semibold">
            <input v-model="form.required" type="checkbox"> Required
          </label>
          <template v-if="selectedInput.type === 'select'">
            <label class="mt-2 flex items-center gap-2 text-[12.5px] font-semibold">
              <input v-model="form.multi_select" type="checkbox"> Multi-select
            </label>
            <label class="mt-2 flex items-center gap-2 text-[12.5px] font-semibold">
              <input v-model="form.searchable" type="checkbox"> Searchable
            </label>
          </template>

          <div class="mt-5 rounded-xl bg-[#F6F7F9] p-4">
            <div class="mb-2 text-[12.5px] font-bold text-[#4B4C4D]">Visibility gating</div>
            <div class="flex gap-2.5">
              <select v-model="form.depends_on_input_id" class="h-10 flex-1 rounded-lg border border-[#DCE0E7] bg-white px-2.5 text-[13px]">
                <option value="">Always visible</option>
                <option v-for="c in dependencyCandidates" :key="c.id" :value="c.id">Depends on: {{ c.title }}</option>
              </select>
              <input v-if="form.depends_on_input_id" v-model="form.depends_on_value" placeholder="required value / option slug" class="h-10 flex-1 rounded-lg border border-[#DCE0E7] bg-white px-3 text-[13px]">
            </div>
            <span v-if="errors.depends_on_input_id" class="mt-1.5 block text-[11px] font-medium text-[#D70D3E]">{{ errors.depends_on_input_id[0] }}</span>
          </div>

          <div v-if="selectedInput.type === 'select'" class="mt-5">
            <div class="mb-2 text-[12.5px] font-bold text-[#4B4C4D]">Options</div>
            <div class="flex flex-col gap-1.5">
              <div v-for="opt in selectedInput.options" :key="opt.id" class="flex items-center justify-between rounded-lg border border-[#ECECEE] px-3 py-2 text-[13px]">
                <span>{{ opt.label }} <span class="font-mono text-[11px] text-[#8A8F98]">{{ opt.slug }}</span></span>
                <button title="Remove" class="text-[#D70D3E]" @click="deleteOption(opt.id)">✕</button>
              </div>
            </div>
            <div class="mt-2 flex gap-2">
              <input v-model="newOptionLabel" placeholder="New option label" class="h-10 flex-1 rounded-lg border border-[#DCE0E7] bg-[#FAFBFD] px-3 text-[13px]" @keyup.enter="addOption">
              <button class="rounded-lg bg-[#F0EDFE] px-3.5 text-[13px] font-semibold text-[#5639E5]" @click="addOption">Add</button>
            </div>
          </div>

          <button class="mt-6 h-10 rounded-full bg-[#5639E5] px-5 text-[13px] font-semibold text-white" @click="saveInput">Save</button>
        </template>
        <div v-else class="flex h-full items-center justify-center text-sm text-[#8A8F98]">Select or add an input to edit it.</div>
      </div>
    </div>
  </div>
</template>
