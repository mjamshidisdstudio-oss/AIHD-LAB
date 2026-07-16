<script setup lang="ts">
import { useServiceDetailStore } from '~/stores/serviceDetail'
import { useToastStore } from '~/stores/toast'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()
const detail = useServiceDetailStore()

const isList = computed(() => route.path === '/')
const isDetail = computed(() => route.name === 'services-slug')
const isUse = computed(() => route.name === 'services-slug-use')
const serviceName = computed(() => detail.current?.name ?? 'Service')

function goList() {
  router.push('/')
}
function goDetail() {
  if (detail.current) router.push(`/services/${detail.current.slug}`)
}

const navIcons: Record<string, string[]> = {
  Home: ['M5 12 3 12l9-9 9 9h-2', 'M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7', 'M10 21v-6h4v6'],
  LAB: ['M9 3h6', 'M10 3v6.5L4.5 18a2 2 0 0 0 1.7 3h11.6a2 2 0 0 0 1.7-3L14 9.5V3', 'M7 15h10'],
  Studio: ['M5 4h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z'],
  'Brand Kit': ['M3 21c3 0 4.5-1.6 4.5-4', 'M13.5 4.5l6 6L10 20l-5 1 1-5z'],
  Pricing: ['M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z', 'M9 9h.01', 'M15 15h.01', 'M15 9l-6 6'],
  Help: ['M3 18v-6a9 9 0 0 1 18 0v6', 'M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z'],
  Products: ['M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z', 'M3.3 7 12 12l8.7-5', 'M12 22V12'],
  More: ['M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z', 'M8 12h.01', 'M12 12h.01', 'M16 12h.01'],
}

const navItems = computed(() =>
  Object.keys(navIcons).map((label) => ({
    label,
    paths: navIcons[label],
    selected: label === 'LAB' && isList.value,
    onClick: () => (label === 'LAB' ? goList() : toast.show(`${label} — coming soon`)),
  })),
)
</script>

<template>
  <div dir="ltr" class="flex min-h-screen bg-white text-[#19191A]">
    <aside
      class="sticky top-0 flex h-screen w-[90px] flex-none flex-col items-center overflow-y-auto border-r border-[#ECEEF1] bg-white py-[18px] pb-5"
      style="z-index: 40"
    >
      <button
        title="New project"
        class="flex h-[54px] w-[54px] flex-none items-center justify-center rounded-full border-0 text-white"
        style="background: linear-gradient(150deg, #6e4cf2, #5639e5); box-shadow: 0 0 0 6px rgba(86, 57, 229, 0.12), 0 10px 22px -6px rgba(86, 57, 229, 0.55)"
        @click="toast.show('Start a new project')"
      >
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="5" /><path d="M12 9v6M9 12h6" /></svg>
      </button>
      <div class="my-4 h-px w-[54px] bg-[#ECEEF1]" />
      <nav class="flex w-full flex-1 flex-col items-center gap-3">
        <button
          v-for="n in navItems"
          :key="n.label"
          class="flex w-full flex-col items-center gap-[5px] border-0 bg-none py-[5px] font-sans text-[11px] transition-colors hover:text-[#5639E5]"
          :class="n.selected ? 'font-bold text-[#19191A]' : 'font-semibold text-[#9A9BA0]'"
          @click="n.onClick"
        >
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path v-for="(d, i) in n.paths" :key="i" :d="d" />
          </svg>
          <span>{{ n.label }}</span>
        </button>
      </nav>
      <div class="flex flex-col items-center gap-3.5 pt-3.5">
        <button
          title="Notifications"
          class="flex h-[46px] w-[46px] items-center justify-center rounded-full border border-[#E9ECF1] bg-white text-[#4B4C4D] hover:bg-[#F6F7F9]"
          @click="toast.show('No new notifications')"
        >
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M13.73 21a2 2 0 0 1-3.46 0" /></svg>
        </button>
        <button
          title="Login"
          class="flex h-[52px] w-[52px] items-center justify-center rounded-full border-0 bg-[#2A2A2E] font-sans text-xs font-bold text-white hover:bg-black"
          style="box-shadow: 0 8px 18px -8px rgba(0, 0, 0, 0.5)"
          @click="toast.show('Sign in to AIHomedesign')"
        >
          Login
        </button>
      </div>
    </aside>

    <div class="min-w-0 flex-1">
      <header class="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-[#ECEEF1] bg-white px-[26px]">
        <div class="flex min-w-0 items-center gap-4">
          <div class="flex flex-none items-center gap-2">
            <svg width="20" height="20" viewBox="0 0 100 100" fill="none"><path d="M50 16 C52 32 59 39 74 41 C59 43 52 50 50 66 C48 50 41 43 26 41 C41 39 48 32 50 16 Z" fill="#5639E5" /><path d="M74 62 C75 72 78 75 88 76 C78 77 75 80 74 90 C73 80 70 77 60 76 C70 75 73 72 74 62 Z" fill="#9747FF" /></svg>
            <span class="text-base font-extrabold tracking-tight">AI HomeDesign</span>
          </div>
          <div class="h-7 w-px flex-none bg-[#E6E8EC]" />
          <nav class="flex min-w-0 items-center gap-[9px]">
            <button
              class="whitespace-nowrap border-0 bg-none p-0 font-sans text-sm"
              :class="isList ? 'font-bold text-[#19191A]' : 'font-semibold text-[#8A8B90]'"
              @click="goList"
            >
              Marketplace
            </button>
            <template v-if="!isList">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#C6C8CE" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-none"><path d="M9 6l6 6-6 6" /></svg>
              <span v-if="isDetail" class="max-w-[220px] truncate text-sm font-bold text-[#19191A]">{{ serviceName }}</span>
              <template v-if="isUse">
                <button class="max-w-[200px] truncate border-0 bg-none p-0 font-sans text-sm font-semibold text-[#8A8B90]" @click="goDetail">{{ serviceName }}</button>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#C6C8CE" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-none"><path d="M9 6l6 6-6 6" /></svg>
                <span class="text-sm font-bold text-[#19191A]">Run</span>
              </template>
            </template>
          </nav>
        </div>
        <div class="flex flex-none items-center gap-3">
          <button
            class="inline-flex h-[38px] items-center gap-2 rounded-full border-0 bg-[#A62BC4] px-[18px] font-sans text-[13px] font-bold text-white hover:bg-[#8F21AA]"
            @click="toast.show('Upgrade plans coming soon')"
          >
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18h18M4 16l-1-9 5.5 4L12 5l3.5 6L21 7l-1 9z" /></svg>
            Upgrade
          </button>
          <div
            title="Your account"
            class="flex h-[38px] w-[38px] flex-none cursor-pointer items-center justify-center rounded-full text-[13px] font-bold text-white"
            style="background: linear-gradient(150deg, #6e4cf2, #5639e5)"
          >
            JL
          </div>
        </div>
      </header>

      <slot />
    </div>

    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0 translate-y-2"
      leave-active-class="transition duration-150 ease-in"
      leave-to-class="opacity-0 translate-y-2"
    >
      <div
        v-if="toast.message"
        class="fixed bottom-[26px] left-1/2 z-[200] -translate-x-1/2 rounded-full bg-[#19191A] px-[22px] py-3 text-[13.5px] font-semibold text-white"
        style="box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35)"
      >
        {{ toast.message }}
      </div>
    </Transition>
  </div>
</template>
