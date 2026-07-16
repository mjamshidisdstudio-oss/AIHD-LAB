// Resolves the persisted token (and re-fetches the user it belongs to)
// before any page-level auth check runs — see middleware/auth.global.ts.
export default defineNuxtPlugin(async () => {
  const auth = useAuthStore()
  await auth.init()
})
