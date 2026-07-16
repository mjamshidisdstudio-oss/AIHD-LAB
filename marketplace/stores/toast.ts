export const useToastStore = defineStore('toast', {
  state: () => ({
    message: null as string | null,
    _handle: null as ReturnType<typeof setTimeout> | null,
  }),
  actions: {
    show(message: string) {
      this.message = message
      if (this._handle) clearTimeout(this._handle)
      this._handle = setTimeout(() => {
        this.message = null
      }, 1700)
    },
  },
})
