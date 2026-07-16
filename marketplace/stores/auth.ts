// Identity here is a core-issued bearer token, never a Laravel session/
// Sanctum user (see AuthenticateWithCoreToken on the API side). There is no
// login UI in the design — a real embedding supplies the token (e.g. via a
// `?token=` query param from the host app); local development falls back to
// the seeded core-stub dev token so the app is usable standalone.
export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: null as string | null,
  }),
  getters: {
    authHeader: (state): Record<string, string> =>
      state.token ? { Authorization: `Bearer ${state.token}` } : {},
  },
  actions: {
    init() {
      if (this.token) return
      if (import.meta.client) {
        const route = useRoute()
        const fromQuery = route.query.token
        if (typeof fromQuery === 'string' && fromQuery.length > 0) {
          this.setToken(fromQuery)
          return
        }

        const stored = window.localStorage.getItem('aihd_token')
        if (stored) {
          this.token = stored
          return
        }
      }

      const config = useRuntimeConfig()
      this.setToken(config.public.devToken)
    },
    setToken(token: string) {
      this.token = token
      if (import.meta.client) {
        window.localStorage.setItem('aihd_token', token)
      }
    },
  },
})
