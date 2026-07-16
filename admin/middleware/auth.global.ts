// Runs on both server and client so the initial render agrees with the
// post-hydration state (no flash/hydration-mismatch): during SSR there is no
// localStorage to read, so auth.init() resolves to "unauthenticated" and this
// redirects to /login exactly as the client will too, before the client-only
// auth.client.ts plugin gets a chance to load a real persisted token.
export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuthStore()
  if (!auth.ready) await auth.init()

  // A hard reload of any deep authenticated route bounces through here on
  // the server (no cookie for SSR to check), landing on /login — carry the
  // originally-requested path so the client can return to it once the
  // auth.client.ts plugin restores the real persisted token.
  if (to.path !== '/login' && !auth.isAuthenticated) {
    return navigateTo({ path: '/login', query: { redirect: to.fullPath } })
  }
  if (to.path === '/login' && auth.isAuthenticated) {
    const redirect = typeof to.query.redirect === 'string' ? to.query.redirect : '/'
    return navigateTo(redirect)
  }
})
