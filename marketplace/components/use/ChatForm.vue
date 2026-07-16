<script setup lang="ts">
import type { ServiceInput } from '~/types/api'
import { useVisibleFlow, type Answers } from '~/composables/useInputFlow'
import { useToastStore } from '~/stores/toast'

const props = defineProps<{
  inputs: ServiceInput[]
  answers: Answers
  serviceName: string
  coinCost: number
}>()

const emit = defineEmits<{
  'update:answers': [value: Answers]
  generate: []
}>()

const toast = useToastStore()
const flow = useVisibleFlow(props.inputs)
const chatStep = ref(0)
const textDraft = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

const steps = computed(() => flow.visibleInputs(props.answers))
const done = computed(() => chatStep.value >= steps.value.length)
const current = computed(() => steps.value[chatStep.value])
const scrollEl = ref<HTMLElement | null>(null)

function setAnswer(slug: string, value: unknown) {
  emit('update:answers', { ...props.answers, [slug]: value })
}

function answerAndAdvance(slug: string, value: unknown) {
  setAnswer(slug, value)
  chatStep.value += 1
  nextTick(scrollToBottom)
}

function skip() {
  chatStep.value += 1
  nextTick(scrollToBottom)
}

function sendText() {
  if (!current.value) return
  const value = textDraft.value
  if (current.value.required && !value.trim()) {
    toast.show('Please type an answer')

    return
  }
  setAnswer(current.value.slug, value)
  textDraft.value = ''
  chatStep.value += 1
  nextTick(scrollToBottom)
}

function pickFile() {
  fileInput.value?.click()
}
function onFileChange(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file && current.value) answerAndAdvance(current.value.slug, file)
}

function formatAnswer(input: ServiceInput): string {
  const value = props.answers[input.slug]
  if (input.type === 'select') {
    const option = input.options.find((o) => o.slug === value)

    return option?.label ?? '—'
  }
  if (input.type === 'boolean') {
    const config = (input.config ?? {}) as Record<string, unknown>

    return value ? String(config.on_label ?? 'Yes') : String(config.off_label ?? 'No')
  }
  if (input.type === 'image' || input.type === 'video') return value ? 'File uploaded' : '—'

  return (value as string) || '—'
}

function scrollToBottom() {
  if (scrollEl.value) scrollEl.value.scrollTop = scrollEl.value.scrollHeight
}

watch(chatStep, () => nextTick(scrollToBottom))
</script>

<template>
  <div class="flex h-[min(72vh,600px)] flex-col overflow-hidden rounded-[22px] border border-[#EEF0F4] bg-white" style="box-shadow: 0 10px 34px -18px rgba(133, 151, 171, .5)">
    <div class="flex flex-none items-center gap-[11px] border-b border-[#ECEEF3] bg-white p-[13px_16px]">
      <div class="flex h-[38px] w-[38px] flex-none items-center justify-center rounded-full text-[15px] font-extrabold text-white" style="background: linear-gradient(140deg, #7b61ff, #5639e5)">{{ serviceName[0] }}</div>
      <div class="min-w-0">
        <div class="text-[14.5px] font-bold">{{ serviceName }}</div>
        <div class="flex items-center gap-1.5 text-[11.5px] font-semibold text-[#22B36B]"><span class="h-1.5 w-1.5 rounded-full bg-[#22B36B]" />bot · online</div>
      </div>
    </div>

    <div ref="scrollEl" class="flex min-h-0 flex-1 flex-col gap-2.5 overflow-y-auto p-[18px_16px]" style="background: linear-gradient(180deg, #f1eefa, #f5f6f9)">
      <div class="flex max-w-[88%] items-end gap-2 self-start">
        <div class="mt-auto flex h-[30px] w-[30px] flex-none items-center justify-center rounded-full text-[13px] font-extrabold text-white" style="background: linear-gradient(140deg, #7b61ff, #5639e5)">{{ serviceName[0] }}</div>
        <div class="rounded-[4px_16px_16px_16px] bg-white p-[10px_14px] text-sm leading-relaxed text-[#232526]">Hi! I'll ask a few quick questions to run "{{ serviceName }}".</div>
      </div>

      <template v-for="(s, i) in steps.slice(0, chatStep)" :key="s.id">
        <div class="flex max-w-[88%] items-end gap-2 self-start">
          <div class="mt-auto h-[30px] w-[30px] flex-none rounded-full" style="background: linear-gradient(140deg, #7b61ff, #5639e5)" />
          <div class="rounded-[4px_16px_16px_16px] bg-white p-[10px_14px] text-sm leading-relaxed text-[#232526]">{{ s.title }}</div>
        </div>
        <div class="max-w-[80%] self-end rounded-[16px_16px_4px_16px] bg-[#5639E5] p-[10px_14px] text-sm font-semibold text-white">{{ formatAnswer(s) }}</div>
      </template>

      <template v-if="!done && current">
        <div class="flex max-w-[88%] items-end gap-2 self-start">
          <div class="mt-auto h-[30px] w-[30px] flex-none rounded-full" style="background: linear-gradient(140deg, #7b61ff, #5639e5)" />
          <div class="rounded-[4px_16px_16px_16px] bg-white p-[10px_14px] text-sm leading-relaxed text-[#232526]">{{ current.title }}</div>
        </div>

        <div v-if="current.type !== 'text'" class="mt-2 grid max-w-[86%] gap-2 self-start" :class="current.type === 'select' && flow.visibleOptions(current, answers).length > 2 ? 'grid-cols-2' : 'grid-cols-1'">
          <template v-if="current.type === 'boolean'">
            <button class="rounded-[13px] border border-[#5639E5] bg-[#5639E5] p-[12px_14px] text-center text-[13.5px] font-bold text-white" @click="answerAndAdvance(current.slug, true)">{{ (current.config as any)?.on_label ?? 'Yes' }}</button>
            <button class="rounded-[13px] border border-[#E4E1F0] bg-white p-[12px_14px] text-center text-[13.5px] font-bold text-[#4A38C7]" @click="answerAndAdvance(current.slug, false)">{{ (current.config as any)?.off_label ?? 'No' }}</button>
          </template>
          <template v-else-if="current.type === 'select'">
            <button v-for="op in flow.visibleOptions(current, answers)" :key="op.id" class="rounded-[13px] border border-[#E4E1F0] bg-white p-[12px_14px] text-center text-[13.5px] font-bold text-[#4A38C7]" @click="answerAndAdvance(current.slug, op.slug)">{{ op.label }}</button>
            <button v-if="!current.required" class="rounded-[13px] border border-[#E4E1F0] bg-white p-[12px_14px] text-center text-[13.5px] font-bold text-[#4A38C7]" @click="skip">Skip</button>
          </template>
          <template v-else-if="current.type === 'image' || current.type === 'video'">
            <input ref="fileInput" type="file" class="hidden" :accept="current.type === 'image' ? 'image/*' : 'video/*'" @change="onFileChange">
            <button class="rounded-[13px] border border-[#5639E5] bg-[#5639E5] p-[12px_14px] text-center text-[13.5px] font-bold text-white" @click="pickFile">📷 Attach a file</button>
            <button v-if="!current.required" class="rounded-[13px] border border-[#E4E1F0] bg-white p-[12px_14px] text-center text-[13.5px] font-bold text-[#4A38C7]" @click="skip">Skip</button>
          </template>
        </div>
      </template>

      <div v-if="done" class="flex max-w-[88%] items-end gap-2 self-start">
        <div class="mt-auto h-[30px] w-[30px] flex-none rounded-full" style="background: linear-gradient(140deg, #7b61ff, #5639e5)" />
        <div class="rounded-[4px_16px_16px_16px] bg-white p-[10px_14px] text-sm leading-relaxed text-[#232526]">Great — everything's ready. Tap Generate to get your result.</div>
      </div>
    </div>

    <div v-if="!done && current?.type === 'text'" class="flex items-center gap-2.5 border-t border-[#ECEEF3] bg-white p-[11px_12px]">
      <input
        v-model="textDraft"
        :placeholder="(current.config as any)?.placeholder ?? 'Type your message…'"
        class="h-11 min-w-0 flex-1 rounded-full border border-[#E1E4E8] bg-[#F6F7F9] px-4 text-sm outline-none"
        @keydown.enter="sendText"
      >
      <button class="flex h-11 w-11 flex-none items-center justify-center rounded-full border-0 bg-[#5639E5] text-white" @click="sendText">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="#fff"><path d="M3 20.5v-6l8-2.5-8-2.5v-6l19 8.5z" /></svg>
      </button>
    </div>
    <div v-else-if="done" class="border-t border-[#ECEEF3] bg-white p-[13px_16px]">
      <button
        class="inline-flex h-12 w-full items-center justify-center gap-2 rounded-full border-0 text-[15px] font-bold text-white"
        style="background: linear-gradient(90deg, #c13bd8, #5639e5)"
        @click="emit('generate')"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="#fff"><path d="M13 2L4.5 13.5H11l-1 8.5 8.5-11.5H12z" /></svg>
        Generate Result
      </button>
      <div class="mt-2 text-center text-[11.5px] text-[#9A9BA0]">Consumes {{ coinCost }} {{ coinCost === 1 ? 'Credit' : 'Credits' }}</div>
    </div>
  </div>
</template>
