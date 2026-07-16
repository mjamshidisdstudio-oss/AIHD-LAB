<script setup lang="ts">
// design-reference/service-admin.dc.html lines 700-710.
import { useServiceDetailStore } from '~/stores/serviceDetail'

const props = defineProps<{ modelValue: string }>()
const emit = defineEmits<{ 'update:modelValue': [string] }>()

const detail = useServiceDetailStore()
const isBuiltin = computed(() => detail.service?.kind === 'internal')

const TABS = computed(() => {
  const tabs = [{ key: 'overview', label: 'Overview' }]
  if (isBuiltin.value) {
    tabs.push(
      { key: 'inputs', label: 'Inputs' },
      { key: 'integration', label: 'Integration' },
      { key: 'outputs', label: 'Outputs' },
      { key: 'logs', label: 'Orders & logs' },
    )
  }
  tabs.push({ key: 'reviews', label: 'Community feedback' })
  return tabs
})

function select(key: string) {
  emit('update:modelValue', key)
}
</script>

<template>
  <div class="flex flex-none gap-1.5 border-b border-[#EBEDF0] bg-white px-7 py-3">
    <button
      v-for="tab in TABS"
      :key="tab.key"
      class="rounded-full px-4 py-2 text-[13px] font-semibold"
      :class="props.modelValue === tab.key ? 'bg-[#F0EDFE] text-[#5639E5]' : 'text-[#6B7280] hover:bg-[#F6F7F9]'"
      @click="select(tab.key)"
    >
      {{ tab.label }}
    </button>
  </div>
</template>
