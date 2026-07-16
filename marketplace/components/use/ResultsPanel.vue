<script setup lang="ts">
import type { Order, OrderResult } from '~/types/api'
import { useToastStore } from '~/stores/toast'

const props = defineProps<{
  order: Order
  coinCost: number
}>()

const emit = defineEmits<{ 'run-again': []; 'another-service': [] }>()

const toast = useToastStore()

const results = computed<OrderResult[]>(() => {
  const all = props.order.requests.flatMap((r) => r.results)

  return [...all].sort((a, b) => a.result_number - b.result_number)
})

async function download(result: OrderResult) {
  try {
    const config = useRuntimeConfig()
    const auth = useAuthStore()
    const blob = await $fetch<Blob>(`/marketplace/results/${result.id}/download`, {
      baseURL: config.public.apiBase,
      headers: { Accept: '*/*', ...auth.authHeader },
      responseType: 'blob',
    })
    const url = URL.createObjectURL(blob as Blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `result-${result.result_number}`
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
    toast.show(`Downloading result ${result.result_number}…`)
  } catch {
    toast.show('Could not download this result')
  }
}
</script>

<template>
  <div>
    <div class="mb-[18px] flex items-center gap-2.5 rounded-2xl border border-[#C7F0D8] bg-[#E9FBF0] p-[14px_18px]">
      <div class="flex h-[34px] w-[34px] flex-none items-center justify-center rounded-full bg-[#16A34A]">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5" /></svg>
      </div>
      <div>
        <div class="text-[15px] font-bold">Your result is ready!</div>
        <div class="mt-0.5 text-[12.5px] text-[#4B4C4D]">
          {{ results.length }} {{ results.length === 1 ? 'output generated' : 'outputs generated' }} · {{ coinCost }} credits charged
        </div>
      </div>
    </div>

    <div class="mb-5 grid grid-cols-1 gap-3.5 sm:grid-cols-2">
      <div v-for="r in results" :key="r.id" class="rounded-2xl border border-[#ECEEF1] bg-white p-3">
        <div v-if="r.type === 'text'" class="rounded-[14px] bg-[#0F1117] p-4 text-[13px] leading-relaxed text-[#8AE4B0]" style="font-family: ui-monospace, monospace">
          {{ r.text_value }}
        </div>
        <div v-else class="relative flex aspect-[4/3] items-center justify-center rounded-[14px]" style="background: linear-gradient(140deg, #7b61ff, #5639e5)">
          <svg v-if="r.type === 'video'" width="52" height="52" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="24" fill="rgba(255,255,255,.9)" />
            <path d="M9.5 8.5v7l6-3.5z" fill="#5639E5" />
          </svg>
          <svg v-else width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="8.5" cy="8.5" r="1.5" /><path d="M21 15l-5-5L5 21" /></svg>
        </div>
        <div class="mt-2.5 flex items-center justify-between">
          <span class="text-[13.5px] font-bold">Output {{ r.result_number }}</span>
          <button
            v-if="r.type !== 'text'"
            class="inline-flex h-[34px] items-center gap-1.5 rounded-full border border-[#E1E4E8] bg-white px-3.5 text-[12.5px] font-semibold text-[#4B4C4D]"
            @click="download(r)"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="M7 10l5 5 5-5" /><path d="M12 15V3" /></svg>
            Download
          </button>
        </div>
      </div>
    </div>

    <div class="flex gap-2.5">
      <button class="h-12 flex-1 rounded-full border border-[#D4D9E3] bg-white text-sm font-bold text-[#4B4C4D]" @click="emit('run-again')">Run again</button>
      <button class="h-12 flex-1 rounded-full border-0 bg-[#5639E5] text-sm font-bold text-white" @click="emit('another-service')">Another service</button>
    </div>
  </div>
</template>
