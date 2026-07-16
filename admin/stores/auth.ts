// Unlike the marketplace client (a core-issued bearer token with a dev
// fallback and no login screen), the admin app has its own users table and a
// real login flow (POST /api/admin/login issues a Sanctum personal access
// token). The token is the only thing persisted; the user profile is
// re-fetched on boot via GET /user so a stale localStorage value can't imply
// a stale identity.
export interface AdminUser {
  id: string
  name: string
  email: string
  is_admin: boolean
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: null as string | null,
    user: null as AdminUser | null,
    ready: false,
  }),
  getters: {
    isAuthenticated: (state): boolean => state.token !== null && state.user !== null,
    authHeader: (state): Record<string, string> =>
      state.token ? { Authorization: `Bearer ${state.token}` } : {},
  },
  actions: {
    async init() {
      if (this.ready) return
      // `ready` is only ever meaningfully set on the CLIENT: during SSR there
      // is no localStorage to check, so this would otherwise return
      // immediately having done nothing, then Pinia's SSR-state hydration
      // would carry that `ready: true` to the client verbatim — permanently
      // short-circuiting this method there too, before it ever gets a chance
      // to read the real persisted token.
      if (!import.meta.client) return
      const stored = window.localStorage.getItem('aihd_admin_token')
      if (stored) {
        this.token = stored
        await this.fetchUser()
      }
      this.ready = true
    },
    async login(email: string, password: string) {
      const config = useRuntimeConfig()
      const response = await $fetch<{ token: string; user: AdminUser }>('/admin/login', {
        baseURL: config.public.apiBase,
        method: 'POST',
        body: { email, password },
      })
      this.setToken(response.token)
      this.user = response.user
    },
    async fetchUser() {
      const config = useRuntimeConfig()
      try {
        this.user = await $fetch<AdminUser>('/user', {
          baseURL: config.public.apiBase,
          headers: this.authHeader,
        })
      } catch {
        this.logout()
      }
    },
    async logout() {
      const config = useRuntimeConfig()
      if (this.token) {
        try {
          await $fetch('/admin/logout', {
            baseURL: config.public.apiBase,
            method: 'POST',
            headers: this.authHeader,
          })
        } catch {
          // Token may already be invalid — clearing local state still logs the user out.
        }
      }
      this.token = null
      this.user = null
      if (import.meta.client) {
        window.localStorage.removeItem('aihd_admin_token')
      }
    },
    setToken(token: string) {
      this.token = token
      if (import.meta.client) {
        window.localStorage.setItem('aihd_admin_token', token)
      }
    },
  },
})
