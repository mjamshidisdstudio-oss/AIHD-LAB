import type { EntryMode, Order } from '~/types/api'

interface SubmitPayload {
  serviceId: string
  answers: Record<string, unknown>
  files: Record<string, File>
  entryMode: EntryMode
  regeneratedFromOrderId?: string | null
}

const POLL_FALLBACK_MS = 4000

export const useOrderStore = defineStore('order', {
  state: () => ({
    current: null as Order | null,
    submitting: false,
    waitingTextIndex: 0,
    error: null as string | null,
    _pollHandle: null as ReturnType<typeof setInterval> | null,
    _waitingHandle: null as ReturnType<typeof setInterval> | null,
    _channelName: null as string | null,
  }),
  getters: {
    isTerminal(state): boolean {
      return state.current?.status === 'completed' || state.current?.status === 'failed'
    },
  },
  actions: {
    async submit(payload: SubmitPayload) {
      this.submitting = true
      this.error = null
      try {
        const form = new FormData()
        form.append('service_id', payload.serviceId)
        form.append('entry_mode', payload.entryMode)
        if (payload.regeneratedFromOrderId) {
          form.append('regenerated_from_order_id', payload.regeneratedFromOrderId)
        }
        for (const [slug, value] of Object.entries(payload.answers)) {
          if (Array.isArray(value)) {
            for (const v of value) form.append(`answers[${slug}][]`, String(v))
          } else if (value !== null && value !== undefined) {
            form.append(`answers[${slug}]`, String(value))
          }
        }
        for (const [slug, file] of Object.entries(payload.files)) {
          form.append(`files[${slug}]`, file)
        }

        const { post } = useApi()
        const { data } = await post<{ data: Order }>('/orders', form)
        this.current = data
        this.waitingTextIndex = 0
        this.track(data)

        return data
      } catch (e: any) {
        this.error = e?.data?.message ?? 'Something went wrong submitting your order.'
        throw e
      } finally {
        this.submitting = false
      }
    },

    async refresh() {
      if (!this.current) return
      const { get } = useApi()
      const { data } = await get<{ data: Order }>(`/orders/${this.current.id}`)
      this.current = data
      if (this.isTerminal) this.stopTracking()

      return data
    },

    track(order: Order) {
      this.stopTracking()
      this.startWaitingRotation()
      this.startPollFallback()
      if (import.meta.client) this.subscribeEcho(order)
    },

    startWaitingRotation() {
      this._waitingHandle = setInterval(() => {
        this.waitingTextIndex += 1
      }, 2600)
    },

    startPollFallback() {
      this._pollHandle = setInterval(() => {
        this.refresh().catch(() => {})
      }, POLL_FALLBACK_MS)
    },

    subscribeEcho(order: Order) {
      const nuxtApp = useNuxtApp()
      const echo = (nuxtApp as any).$echo
      if (!echo) return

      // Keyed by the order's OWNING user_ref (not the bearer token itself —
      // the two are different strings, see config/core.php's dev stub) so
      // one subscription covers every order this user places while mounted.
      this._channelName = `orders.${order.user_ref}`
      echo.private(this._channelName).listen('.order.completed', (payload: { order_id: string }) => {
        if (payload.order_id === order.id) {
          this.refresh().catch(() => {})
        }
      })
    },

    stopTracking() {
      if (this._pollHandle) clearInterval(this._pollHandle)
      if (this._waitingHandle) clearInterval(this._waitingHandle)
      this._pollHandle = null
      this._waitingHandle = null

      if (this._channelName && import.meta.client) {
        const nuxtApp = useNuxtApp()
        const echo = (nuxtApp as any).$echo
        echo?.leave(this._channelName)
      }
      this._channelName = null
    },

    reset() {
      this.stopTracking()
      this.current = null
      this.error = null
      this.waitingTextIndex = 0
    },
  },
})
