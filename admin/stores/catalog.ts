import type { Service } from '~/types/api'

export const useCatalogStore = defineStore('catalog', {
  state: () => ({
    services: [] as Service[],
    loaded: false,
  }),
  getters: {
    serviceCount: (state): number => state.services.length,
    activeCount: (state): number => state.services.filter((s) => s.status === 'active').length,
    inactiveCount: (state): number => state.services.filter((s) => s.status !== 'active').length,
  },
  actions: {
    async fetchAll(force = false) {
      if (this.loaded && !force) return
      const api = useApi()
      const response = await api.get<{ data: Service[] }>('/admin/services')
      this.services = response.data
      this.loaded = true
    },
    async create(payload: { slug: string; name: string; kind: 'internal' | 'external'; category: string; external_url?: string }) {
      const api = useApi()
      const response = await api.post<{ data: Service }>('/admin/services', payload)
      this.services.unshift(response.data)
      return response.data
    },
    async update(id: string, payload: Record<string, unknown>) {
      const api = useApi()
      const response = await api.patch<{ data: Service }>(`/admin/services/${id}`, payload)
      const index = this.services.findIndex((s) => s.id === id)
      if (index !== -1) this.services[index] = response.data
      return response.data
    },
    async toggleActive(service: Service) {
      const next = service.status === 'active' ? 'paused' : 'active'
      return this.update(service.id, { status: next })
    },
  },
})
