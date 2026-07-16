import type { ServiceCard } from '~/types/api'

export type ListSort = 'hot' | 'top' | 'new'

/**
 * Fetches the full active catalog once; category/saved/query/sort are all
 * applied client-side (matching the design's instant, no-reload tab/filter
 * switching) from services' cached columns only — never a per-filter refetch.
 */
export const useCatalogStore = defineStore('catalog', {
  state: () => ({
    services: [] as ServiceCard[],
    loading: false,
    loaded: false,
    sort: 'hot' as ListSort,
    category: 'all' as string,
    query: '' as string,
    savedOnly: false,
    layout: 'grid' as 'grid' | 'board',
  }),
  getters: {
    categories(state): string[] {
      return Array.from(new Set(state.services.map((s) => s.category)))
    },
    filtered(state): ServiceCard[] {
      let list = state.services.slice()
      if (state.savedOnly) list = list.filter((s) => s.is_bookmarked)
      if (state.category !== 'all') list = list.filter((s) => s.category === state.category)
      const q = state.query.trim().toLowerCase()
      if (q) {
        list = list.filter(
          (s) => s.name.toLowerCase().includes(q) || (s.tagline ?? '').toLowerCase().includes(q),
        )
      }

      if (state.sort === 'top') {
        list.sort((a, b) => b.vote_up - a.vote_up)
      } else if (state.sort === 'new') {
        list.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())
      } else {
        // "Hot this week": trending_rank is the cached column purpose-built
        // for this — lower rank first, unranked services trail behind.
        list.sort((a, b) => (a.trending_rank ?? Infinity) - (b.trending_rank ?? Infinity))
      }

      return list
    },
  },
  actions: {
    async fetchList() {
      this.loading = true
      try {
        const { get } = useApi()
        const { data } = await get<{ data: ServiceCard[] }>('/marketplace/services')
        this.services = data
        this.loaded = true
      } finally {
        this.loading = false
      }
    },

    async vote(serviceId: string, value: 1 | -1) {
      const { post } = useApi()
      const result = await post<{ my_vote: 1 | -1 | null; vote_up: number; vote_down: number }>(
        `/marketplace/services/${serviceId}/vote`,
        { value },
      )
      this.applyVote(serviceId, result)

      return result
    },

    applyVote(serviceId: string, result: { my_vote: 1 | -1 | null; vote_up: number; vote_down: number }) {
      const service = this.services.find((s) => s.id === serviceId)
      if (service) {
        service.my_vote = result.my_vote
        service.vote_up = result.vote_up
        service.vote_down = result.vote_down
      }
    },

    async toggleBookmark(serviceId: string) {
      const { post } = useApi()
      const result = await post<{ bookmarked: boolean }>(`/marketplace/services/${serviceId}/bookmark`)
      const service = this.services.find((s) => s.id === serviceId)
      if (service) service.is_bookmarked = result.bookmarked

      return result.bookmarked
    },

    async logExternalClick(serviceId: string) {
      const { post } = useApi()
      await post(`/marketplace/services/${serviceId}/external-click`)
    },
  },
})
