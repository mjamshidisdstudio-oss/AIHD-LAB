<script setup lang="ts">
import type { ServiceInput } from '~/types/api'
import { useVisibleFlow, type Answers } from '~/composables/useInputFlow'
import { useToastStore } from '~/stores/toast'

const props = defineProps<{
  inputs: ServiceInput[]
  answers: Answers
  coinCost: number
}>()

const emit = defineEmits<{
  'update:answers': [value: Answers]
  generate: []
}>()

const toast = useToastStore()
const flow = useVisibleFlow(props.inputs)
const step = ref(0)

const steps = computed(() => flow.visibleInputs(props.answers))
const total = computed(() => steps.value.length)
const currentStep = computed(() => steps.value[Math.min(step.value, total.value - 1)])
const isLast = computed(() => step.value >= total.value - 1)
const blocked = computed(() => !!currentStep.value && currentStep.value.required && !flow.isAnswered(currentStep.value, props.answers))

function setAnswer(slug: string, value: unknown) {
  emit('update:answers', { ...props.answers, [slug]: value })
}

function next() {
  if (blocked.value) {
    toast.show('This field is required')

    return
  }
  if (isLast.value) emit('generate')
  else step.value = Math.min(total.value - 1, step.value + 1)
}
function back() {
  step.value = Math.max(0, step.value - 1)
}
</script>

<template>
  <div v-if="currentStep" class="overflow-hidden rounded-[22px] border border-[#EEF0F4] bg-white" style="box-shadow: 0 10px 34px -18px rgba(133, 151, 171, .5)">
    <div class="p-[20px_22px_6px]">
      <div class="mb-2.5 flex items-center justify-between">
        <span class="whitespace-nowrap text-[12.5px] font-bold text-[#4B4C4D]">Step {{ step + 1 }} of {{ total }}</span>
        <div class="flex gap-[5px]">
          <button
            v-for="(_, i) in steps"
            :key="i"
            class="h-[7px] w-[7px] rounded-full border-0 p-0"
            :class="i === step ? 'bg-[#5639E5]' : 'bg-[#D4D9E3]'"
            @click="step = i"
          />
        </div>
      </div>
      <div class="h-1.5 overflow-hidden rounded-full bg-[#EFF1F4]">
        <div class="h-full rounded-full transition-all" style="background: linear-gradient(90deg, #5639e5, #9747ff)" :style="{ width: `${((step + 1) / total) * 100}%` }" />
      </div>
    </div>
    <div class="p-[20px_22px_4px]">
      <div class="mb-4 flex items-center gap-1.5">
        <span class="text-[17px] font-bold">{{ currentStep.title }}</span>
        <span v-if="currentStep.required" class="text-[15px] text-[#D92D2D]">*</span>
      </div>
      <InputControl
        :input="currentStep"
        :options="flow.visibleOptions(currentStep, answers)"
        :model-value="answers[currentStep.slug]"
        @update:model-value="setAnswer(currentStep.slug, $event)"
      />
    </div>
    <div class="flex gap-2.5 p-[22px]">
      <button
        class="h-12 rounded-full border border-[#D4D9E3] bg-white px-[22px] text-sm font-semibold"
        :class="step === 0 ? 'cursor-default text-[#C5C8CE]' : 'cursor-pointer text-[#4B4C4D]'"
        :disabled="step === 0"
        @click="back"
      >
        Back
      </button>
      <button
        class="inline-flex h-12 flex-1 items-center justify-center gap-2 rounded-full border-0 text-[15px] font-bold text-white"
        :class="blocked ? 'cursor-not-allowed bg-[#D4D9E3]' : 'cursor-pointer'"
        :style="!blocked ? { background: isLast ? 'linear-gradient(90deg,#C13BD8,#5639E5)' : '#5639E5' } : {}"
        @click="next"
      >
        <svg v-if="isLast" width="18" height="18" viewBox="0 0 24 24" fill="#fff"><path d="M13 2L4.5 13.5H11l-1 8.5 8.5-11.5H12z" /></svg>
        {{ isLast ? 'Generate Result' : 'Next' }}
      </button>
    </div>
    <div v-if="isLast" class="-mt-2.5 p-[0_22px_18px] text-center text-[11.5px] text-[#9A9BA0]">
      Consumes {{ coinCost }} {{ coinCost === 1 ? 'Credit' : 'Credits' }}
    </div>
  </div>
</template>
