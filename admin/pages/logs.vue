<script setup lang="ts">
// Hand off to Opcodes Log Viewer on the Laravel API host. The API
// middleware accepts our Sanctum token once, starts a web session, then
// strips ?token= from the URL.
const config = useRuntimeConfig()
const auth = useAuthStore()

onMounted(() => {
  if (!auth.token) {
    navigateTo('/login')
    return
  }

  const apiOrigin = config.public.apiBase.replace(/\/api\/?$/, '')
  const url = `${apiOrigin}/log-viewer?token=${encodeURIComponent(auth.token)}`
  window.location.replace(url)
})
</script>

<template>
  <div class="flex h-screen items-center justify-center text-sm text-[#8A8F98]">
    Opening Log Viewer…
  </div>
</template>
