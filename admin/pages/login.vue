<script setup lang="ts">
// Not in the design file (its prototype starts from an already-authenticated
// session) — built with the same visual system (brand purple, Inter Display,
// pill buttons, card radii) rather than left unstyled. Flagged in the PR.
definePageMeta({ layout: 'blank' })

const auth = useAuthStore()
const route = useRoute()
const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const loading = ref(false)

// A hard reload of a deep authenticated route always lands here first (SSR
// has no cookie/localStorage to check — see middleware/auth.global.ts).
// Hydrating this server-rendered page is NOT itself a fresh navigation, so
// the global middleware doesn't get a second chance to bounce back on its
// own once the client-only auth plugin restores the real token — this page
// has to do that bounce itself once mounted.
onMounted(() => {
  if (auth.isAuthenticated) {
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    navigateTo(redirect)
  }
})

async function submit() {
  error.value = null
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    await navigateTo(redirect)
  } catch (e: any) {
    error.value = e?.data?.errors?.email?.[0] ?? 'Could not sign in with those credentials.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-[#F6F7F9] px-4">
    <div class="w-full max-w-[380px] rounded-[20px] border border-[#ECECEE] bg-white p-8 shadow-[0_1px_2px_rgba(133,151,171,.06)]">
      <div class="mb-7 flex items-center gap-3">
        <div class="flex h-10 w-10 flex-none items-center justify-center rounded-xl" style="background: linear-gradient(150deg, #6e4cf2, #5639e5); box-shadow: 0 4px 14px rgba(86, 57, 229, .4)">
          <svg width="22" height="22" viewBox="0 0 100 100" fill="none"><path d="M50 16 C52 32 59 39 74 41 C59 43 52 50 50 66 C48 50 41 43 26 41 C41 39 48 32 50 16 Z" fill="#fff" /><path d="M74 62 C75 72 78 75 88 76 C78 77 75 80 74 90 C73 80 70 77 60 76 C70 75 73 72 74 62 Z" fill="#E5E0FD" /></svg>
        </div>
        <div>
          <div class="text-[11.5px] font-semibold uppercase tracking-[.06em] text-[#8A8F98]">AIHD Lab · Admin</div>
          <div class="text-lg font-extrabold tracking-[-.02em]">Sign in</div>
        </div>
      </div>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <label class="flex flex-col gap-1.5">
          <span class="text-[13px] font-semibold text-[#4B4C4D]">Email</span>
          <input
            v-model="email"
            type="email"
            required
            autocomplete="username"
            placeholder="admin@aihd.lab"
            class="h-[46px] rounded-[12px] border border-[#EBEBED] px-4 text-[14.5px] text-[#19191A] focus:border-[#5639E5]"
          >
        </label>
        <label class="flex flex-col gap-1.5">
          <span class="text-[13px] font-semibold text-[#4B4C4D]">Password</span>
          <input
            v-model="password"
            type="password"
            required
            autocomplete="current-password"
            placeholder="••••••••"
            class="h-[46px] rounded-[12px] border border-[#EBEBED] px-4 text-[14.5px] text-[#19191A] focus:border-[#5639E5]"
          >
        </label>

        <p v-if="error" class="text-[13px] font-medium text-[#D70D3E]">{{ error }}</p>

        <button
          type="submit"
          :disabled="loading"
          class="mt-2 inline-flex h-[46px] items-center justify-center rounded-full bg-[#5639E5] text-[14px] font-semibold text-white shadow-[0_2px_10px_rgba(86,57,229,.28)] disabled:opacity-60"
        >
          {{ loading ? 'Signing in…' : 'Sign in' }}
        </button>
      </form>
    </div>
  </div>
</template>
