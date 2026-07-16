<script setup lang="ts">
import type { ServiceInput, ServiceInputOption } from '~/types/api'

const props = defineProps<{
  input: ServiceInput
  options: ServiceInputOption[]
  modelValue: unknown
}>()

const emit = defineEmits<{ 'update:modelValue': [value: unknown] }>()

const config = computed(() => (props.input.config ?? {}) as Record<string, unknown>)
const display = computed(() => String(config.value.display ?? 'list'))
const placeholder = computed(() => String(config.value.placeholder ?? ''))
const onLabel = computed(() => String(config.value.on_label ?? 'On'))
const offLabel = computed(() => String(config.value.off_label ?? 'Off'))
const booleanValue = computed(() => (props.modelValue === undefined ? false : Boolean(props.modelValue)))

const fileInput = ref<HTMLInputElement | null>(null)
const filePreviewName = computed(() => (props.modelValue instanceof File ? props.modelValue.name : null))

function pickFile() {
  fileInput.value?.click()
}
function onFileChange(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file) emit('update:modelValue', file)
}

function select(slug: string) {
  emit('update:modelValue', slug)
}
</script>

<template>
  <div>
    <!-- text -->
    <textarea
      v-if="input.type === 'text'"
      :value="(modelValue as string) ?? ''"
      :placeholder="placeholder"
      rows="3"
      class="w-full rounded-2xl border-[1.5px] border-[#E1E4E8] bg-white p-[13px_15px] text-sm leading-relaxed"
      @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
    />

    <!-- boolean -->
    <div v-else-if="input.type === 'boolean'" class="flex items-center justify-between gap-3.5 rounded-2xl bg-[#F7F7F9] px-4 py-[13px]">
      <span class="text-[13.5px] font-semibold text-[#4B4C4D]">{{ booleanValue ? onLabel : offLabel }}</span>
      <button
        title="Toggle"
        class="flex h-[26px] w-[46px] items-center rounded-full border-0 p-0.5 transition-all"
        :class="booleanValue ? 'justify-end bg-[#2FBE5F]' : 'justify-start bg-[#D4D9E3]'"
        @click="emit('update:modelValue', !booleanValue)"
      >
        <span class="block h-[22px] w-[22px] rounded-full bg-white shadow" />
      </button>
    </div>

    <!-- select -->
    <template v-else-if="input.type === 'select'">
      <div v-if="display === 'swatch'" class="grid grid-cols-4 gap-2.5">
        <button
          v-for="op in options"
          :key="op.id"
          class="flex flex-col items-center gap-2 rounded-2xl border-[1.5px] p-[10px_6px]"
          :class="modelValue === op.slug ? 'border-[#5639E5]' : 'border-[#E1E4E8]'"
          @click="select(op.slug)"
        >
          <div class="h-9 w-9 rounded-[9px]" :style="{ background: op.color ?? '#DDD' }" />
          <span class="text-[12.5px]" :class="modelValue === op.slug ? 'font-bold text-[#5639E5]' : 'font-semibold text-[#4B4C4D]'">{{ op.label }}</span>
        </button>
      </div>
      <div v-else-if="display === 'grid'" class="grid grid-cols-3 gap-2.5">
        <button
          v-for="op in options"
          :key="op.id"
          class="rounded-[13px] border-[1.5px] p-[14px_6px] text-[13.5px]"
          :class="modelValue === op.slug ? 'border-[#5639E5] font-bold text-[#5639E5]' : 'border-[#E1E4E8] font-semibold text-[#323233]'"
          @click="select(op.slug)"
        >
          {{ op.label }}
        </button>
      </div>
      <div v-else class="flex flex-col gap-2.5">
        <button
          v-for="op in options"
          :key="op.id"
          class="flex items-center justify-between rounded-[13px] border-[1.5px] p-[13px_16px]"
          :class="modelValue === op.slug ? 'border-[#5639E5]' : 'border-[#E1E4E8]'"
          @click="select(op.slug)"
        >
          <span :class="modelValue === op.slug ? 'font-bold text-[#5639E5]' : 'font-semibold text-[#323233]'">{{ op.label }}</span>
          <span class="flex h-[19px] w-[19px] items-center justify-center rounded-full border-2" :class="modelValue === op.slug ? 'border-[#5639E5]' : 'border-[#CDD3DE]'">
            <span v-if="modelValue === op.slug" class="h-[9px] w-[9px] rounded-full bg-[#5639E5]" />
          </span>
        </button>
      </div>
    </template>

    <!-- image / video -->
    <div
      v-else-if="input.type === 'image' || input.type === 'video'"
      class="cursor-pointer rounded-2xl border-[1.5px] border-dashed p-[22px_20px] text-center transition"
      :class="modelValue ? 'border-[#5639E5] bg-[#F5F3FE]' : 'border-[#CDD3DE] bg-[#FAFBFD]'"
      @click="pickFile"
    >
      <input ref="fileInput" type="file" class="hidden" :accept="input.type === 'image' ? 'image/*' : 'video/*'" @change="onFileChange">
      <div v-if="filePreviewName" class="flex items-center gap-3 text-left">
        <div class="h-12 w-12 flex-none rounded-[11px]" style="background: linear-gradient(135deg, #c9bcf6, #8e74ee)" />
        <div class="min-w-0">
          <div class="text-sm font-bold">{{ input.title }}</div>
          <div class="mt-0.5 truncate text-xs text-[#7D7E80]">{{ filePreviewName }} · uploaded</div>
        </div>
      </div>
      <div v-else>
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#969799" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="mx-auto"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="M17 8l-5-5-5 5" /><path d="M12 3v12" /></svg>
        <div class="mt-2.5 text-sm font-semibold">Click to upload "{{ input.title }}"</div>
        <div class="mt-[5px] text-xs font-semibold" :class="input.required ? 'text-[#D92D2D]' : 'text-[#969799]'">{{ input.required ? 'Required' : 'Optional' }}</div>
      </div>
    </div>
  </div>
</template>
