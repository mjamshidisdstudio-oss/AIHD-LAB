<script setup lang="ts">
import type { ServiceWaitingText } from '~/types/api'

const props = defineProps<{
  waitingTexts: ServiceWaitingText[]
  index: number
}>()

const sorted = computed(() => [...props.waitingTexts].sort((a, b) => a.sort_order - b.sort_order))
const safeIndex = computed(() => Math.min(props.index, Math.max(0, sorted.value.length - 1)))
const currentText = computed(() => sorted.value[safeIndex.value]?.text ?? 'Processing…')
const total = computed(() => Math.max(1, sorted.value.length))
</script>

<template>
  <div class="rounded-[22px] border border-[#EEF0F4] bg-white p-[46px_30px] text-center" style="box-shadow: 0 10px 34px -18px rgba(133, 151, 171, .5)">
    <div class="mx-auto mb-[22px] h-[60px] w-[60px] animate-spin rounded-full border-4 border-[#EFEBFF]" style="border-top-color: #5639e5" />
    <div class="mb-2 text-[17px] font-bold">{{ currentText }}</div>
    <div class="mb-[22px] text-[13px] text-[#969799]">Step {{ safeIndex + 1 }} of {{ total }}</div>
    <div class="mx-auto h-[7px] max-w-[340px] overflow-hidden rounded-full bg-[#EFF1F4]">
      <div class="h-full rounded-full transition-all duration-500" style="background: linear-gradient(90deg, #5639e5, #9747ff)" :style="{ width: `${((safeIndex + 1) / total) * 100}%` }" />
    </div>
  </div>
</template>
