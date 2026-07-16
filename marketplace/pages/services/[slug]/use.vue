<script setup lang="ts">
import { useServiceDetailStore } from '~/stores/serviceDetail'
import { useOrderStore } from '~/stores/order'
import type { Answers } from '~/composables/useInputFlow'

const route = useRoute()
const router = useRouter()
const detail = useServiceDetailStore()
const order = useOrderStore()

const slug = computed(() => String(route.params.slug))

await useAsyncData(`service-use-${slug.value}`, () => detail.fetch(slug.value), { watch: [slug] })

const service = computed(() => detail.current)
const version = computed(() => service.value?.version)

type Phase = 'form' | 'loading' | 'results'
const phase = ref<Phase>('form')
const mode = ref<'wizard' | 'chat'>('wizard')
const answers = ref<Answers>({})
// Captured from order.current.id before each reset, so "Run again" still
// chains the next submission into the same root_order_id lineage even
// though the tracked order itself is cleared.
const lastOrderId = ref<string | null>(null)

onBeforeUnmount(() => order.reset())

watch(
  () => order.current?.status,
  (status) => {
    if (!status) return
    if (status === 'processing') phase.value = 'loading'
    else phase.value = 'results'
  },
)

function resetForm() {
  lastOrderId.value = order.current?.id ?? lastOrderId.value
  answers.value = {}
  phase.value = 'form'
  order.reset()
}

function exitToDetail() {
  order.reset()
  router.push(`/services/${slug.value}`)
}

async function generate() {
  if (!service.value || !version.value) return
  const files: Record<string, File> = {}
  const scalarAnswers: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(answers.value)) {
    if (value instanceof File) files[key] = value
    else scalarAnswers[key] = value
  }

  await order.submit({
    serviceId: service.value.id,
    answers: scalarAnswers,
    files,
    entryMode: mode.value,
    regeneratedFromOrderId: lastOrderId.value,
  })
}

function runAgain() {
  resetForm()
}

function anotherService() {
  order.reset()
  router.push('/')
}

const phaseLabel = computed(() => {
  if (phase.value === 'loading') return 'Generating…'
  if (phase.value === 'results') return 'Result'

  return mode.value === 'chat' ? 'Chat to request' : 'Request form'
})

const hasFailedRequest = computed(() => order.current?.requests.some((r) => r.status === 'failed') ?? false)
</script>

<template>
  <main v-if="service && version" class="mx-auto max-w-[640px] px-[22px] py-[26px] pb-[70px]">
    <button class="mb-4 inline-flex h-[34px] items-center gap-1.5 rounded-full border border-[#E1E4E8] bg-white px-[13px] text-[12.5px] font-semibold text-[#4B4C4D]" @click="exitToDetail">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6" /></svg>
      Back to service
    </button>

    <div class="mb-[18px] flex items-center justify-between">
      <div>
        <div class="text-xl font-extrabold tracking-tight">{{ service.name }}</div>
        <div class="mt-0.5 text-[12.5px] text-[#969799]">{{ phaseLabel }}</div>
      </div>
      <button title="Close" class="flex h-[38px] w-[38px] items-center justify-center rounded-[11px] border border-[#E1E4E8] bg-white text-[#4B4C4D]" @click="exitToDetail">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
      </button>
    </div>

    <div v-if="phase === 'form'" class="mb-[18px] flex gap-1 rounded-2xl bg-[#EDEFF3] p-1">
      <button
        class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-[11px] border-0 py-2.5 text-[13.5px] font-bold"
        :class="mode === 'wizard' ? 'bg-white text-[#5639E5] shadow-sm' : 'bg-transparent text-[#7D7E80]'"
        @click="mode = 'wizard'"
      >
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h10M4 18h7" /></svg>Wizard
      </button>
      <button
        class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-[11px] border-0 py-2.5 text-[13.5px] font-bold"
        :class="mode === 'chat' ? 'bg-white text-[#5639E5] shadow-sm' : 'bg-transparent text-[#7D7E80]'"
        @click="mode = 'chat'"
      >
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-8.5 8.5 8.5 8.5 0 0 1-3.6-.8L3 21l1.9-5.4A8.38 8.38 0 0 1 4 11.5 8.5 8.5 0 0 1 12.5 3 8.38 8.38 0 0 1 21 11.5z" /></svg>Chat
      </button>
    </div>

    <template v-if="phase === 'form'">
      <WizardForm
        v-if="mode === 'wizard'"
        v-model:answers="answers"
        :inputs="version.inputs"
        :coin-cost="version.coin_cost"
        @generate="generate"
      />
      <ChatForm
        v-else
        v-model:answers="answers"
        :inputs="version.inputs"
        :service-name="service.name"
        :coin-cost="version.coin_cost"
        @generate="generate"
      />
    </template>

    <LoadingPanel v-else-if="phase === 'loading'" :waiting-texts="version.waiting_texts" :index="order.waitingTextIndex" />

    <div v-else-if="hasFailedRequest" class="rounded-[22px] border border-[#F7D7D7] bg-[#FEECEC] p-[30px] text-center">
      <div class="mb-2 text-[17px] font-bold text-[#D92D2D]">This run didn't complete</div>
      <div class="mb-5 text-sm text-[#8A3B3B]">Your credits have been refunded. You can try again.</div>
      <button class="h-12 w-full rounded-full border-0 bg-[#5639E5] text-sm font-bold text-white" @click="runAgain">Try again</button>
    </div>

    <ResultsPanel
      v-else-if="order.current"
      :order="order.current"
      :coin-cost="version.coin_cost"
      @run-again="runAgain"
      @another-service="anotherService"
    />
  </main>
</template>
