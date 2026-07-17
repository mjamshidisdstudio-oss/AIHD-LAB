export const useToastStore = defineStore('toast', {
  state: () => ({
    message: null as string | null,
  }),
  actions: {
    show(message: string, durationMs = 2600) {
      this.message = message
      setTimeout(() => {
        if (this.message === message) this.message = null
      }, durationMs)
    },
  },
})
