import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

/**
 * One Echo instance for the app, authenticated via BroadcastAuthController
 * (bearer token, not cookies) — see routes/api.php's marketplace group and
 * routes/channels.php on the API side for why a custom authEndpoint is
 * needed instead of Echo's default /broadcasting/auth.
 */
export default defineNuxtPlugin(() => {
  const config = useRuntimeConfig()
  const auth = useAuthStore()
  // Synchronous — resolves query param / localStorage / dev fallback before
  // Echo reads the auth header below, so there's no race on first load.
  auth.init()

  ;(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher

  const echo = new Echo({
    broadcaster: 'pusher',
    key: config.public.broadcastKey,
    wsHost: config.public.broadcastHost || undefined,
    wsPort: Number(config.public.broadcastPort) || 443,
    wssPort: Number(config.public.broadcastPort) || 443,
    forceTLS: config.public.broadcastScheme === 'https',
    cluster: config.public.broadcastCluster,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${config.public.apiBase}/marketplace/broadcasting/auth`,
    auth: {
      headers: {
        Accept: 'application/json',
        ...auth.authHeader,
      },
    },
  })

  return {
    provide: { echo },
  }
})
