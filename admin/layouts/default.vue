<script setup lang="ts">
// Sidebar per design-reference/service-admin.dc.html lines 548-581: logo,
// Services nav (only functional nav item — API usage/Team/General are
// decorative in the design, no destination described in the prompt), avatar.
import { useCatalogStore } from '~/stores/catalog'
import { useToastStore } from '~/stores/toast'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()
const catalog = useCatalogStore()
const auth = useAuthStore()

const isServicesActive = computed(() => route.path === '/' || route.path.startsWith('/services'))
const isLogsActive = computed(() => route.path === '/logs')

onMounted(() => {
  catalog.fetchAll()
})

function goList() {
  router.push('/')
}

async function signOut() {
  await auth.logout()
  await navigateTo('/login')
}
</script>

<template>
  <div style="min-height: 100vh; display: flex; flex-direction: row">
    <aside class="sticky top-0 flex h-screen w-[92px] flex-none flex-col items-center border-r border-[#ECECEE] bg-white py-4 pb-3.5">
      <div class="mb-[22px]">
        <div class="flex h-10 w-10 items-center justify-center rounded-xl" style="background: linear-gradient(150deg, #6e4cf2, #5639e5); box-shadow: 0 4px 14px rgba(86, 57, 229, .4)">
          <svg width="22" height="22" viewBox="0 0 100 100" fill="none"><path d="M50 16 C52 32 59 39 74 41 C59 43 52 50 50 66 C48 50 41 43 26 41 C41 39 48 32 50 16 Z" fill="#fff" /><path d="M74 62 C75 72 78 75 88 76 C78 77 75 80 74 90 C73 80 70 77 60 76 C70 75 73 72 74 62 Z" fill="#E5E0FD" /></svg>
        </div>
      </div>

      <nav class="flex w-full flex-1 flex-col items-center gap-[5px]">
        <button
          class="flex w-[74px] flex-col items-center gap-1 rounded-[14px] border-0 bg-none py-2.5 text-[11px] font-semibold"
          :class="isServicesActive ? 'bg-[#F0EDFE] text-[#5639E5]' : 'text-[#8A8F98] hover:bg-[#F6F7F9]'"
          @click="goList"
        >
          <span class="relative flex">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /></svg>
            <span v-if="catalog.serviceCount > 0" class="absolute -right-[11px] -top-1.5 rounded-full bg-[#5639E5] px-[5px] text-[9px] font-bold leading-[1.6] text-white">{{ catalog.serviceCount }}</span>
          </span>
          Services
        </button>
        <button class="flex w-[74px] flex-col items-center gap-1 rounded-[14px] border-0 bg-none py-2.5 text-[11px] font-semibold text-[#8A8F98]" @click="toast.show('API usage — coming soon')">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18" /><path d="m19 9-5 5-4-4-3 3" /></svg>
          API usage
        </button>
        <button class="flex w-[74px] flex-col items-center gap-1 rounded-[14px] border-0 bg-none py-2.5 text-[11px] font-semibold text-[#8A8F98]" @click="toast.show('Team — coming soon')">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></svg>
          Team
        </button>
        <button
          class="flex w-[74px] flex-col items-center gap-1 rounded-[14px] border-0 bg-none py-2.5 text-[11px] font-semibold"
          :class="isLogsActive ? 'bg-[#F0EDFE] text-[#5639E5]' : 'text-[#8A8F98] hover:bg-[#F6F7F9]'"
          @click="router.push('/logs')"
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" /><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" /></svg>
          Logs
        </button>
      </nav>

      <button
        :title="`${auth.user?.name ?? 'Admin'} · Platform admin — sign out`"
        class="mt-3.5 flex h-[38px] w-[38px] flex-none items-center justify-center rounded-full border-0 text-[13px] font-bold text-white"
        style="background: linear-gradient(150deg, #6e4cf2, #5639e5)"
        @click="signOut"
      >
        {{ (auth.user?.name ?? 'A D').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase() }}
      </button>
    </aside>

    <main class="min-w-0 flex-1" style="display: flex; flex-direction: column; height: 100vh; overflow: hidden">
      <slot />
    </main>

    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0 translate-y-2"
      leave-active-class="transition duration-150 ease-in"
      leave-to-class="opacity-0 translate-y-2"
    >
      <div
        v-if="toast.message"
        class="fixed bottom-[26px] left-1/2 z-[200] -translate-x-1/2 rounded-full bg-[#19191A] px-[22px] py-3 text-[13.5px] font-semibold text-white"
        style="box-shadow: 0 12px 30px rgba(0, 0, 0, .35)"
      >
        {{ toast.message }}
      </div>
    </Transition>
  </div>
</template>
