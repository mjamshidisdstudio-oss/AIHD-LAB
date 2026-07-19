import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

/**
 * One Echo instance for the app, authenticated via BroadcastAuthController —
 * bearer token under the normal core-auth path, or (Phase L2's
 * LAB_AUTH_MODE=anonymous) the same signed cookie every other API call uses.
 * See routes/api.php's marketplace group and routes/channels.php on the API
 * side for why a custom endpoint is needed instead of Echo's default
 * /broadcasting/auth.
 *
 * A custom `authorizer` (not the authEndpoint/auth.headers shorthand) is
 * required for the cookie path: pusher-js's own default ajax authorizer
 * never sets `withCredentials`, so a cross-origin auth request would carry
 * no cookie and get issued a brand-new anonymous identity — one that then
 * fails the channel's own userRef ownership check against the order it's
 * trying to subscribe to. `credentials: 'include'` on a plain fetch() is
 * what useApi.ts relies on too, for the same reason. `authorizer` (a
 * pusher-js/Echo option, not `channelAuthorizer` -- there is no such top-
 * level option, it is silently ignored) is the supported hook for fully
 * replacing the default authorizer with a custom one.
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
    authorizer: (channel: { name: string }) => ({
      authorize(socketId: string, callback: (error: Error | null, data: unknown) => void) {
        $fetch(`${config.public.apiBase}/marketplace/broadcasting/auth`, {
          method: 'POST',
          credentials: 'include',
          headers: {
            Accept: 'application/json',
            ...auth.authHeader,
          },
          body: { channel_name: channel.name, socket_id: socketId },
        })
          .then((data) => callback(null, data))
          .catch((err) => callback(err as Error, null))
      },
    }),
  })

  return {
    provide: { echo },
  }
})
