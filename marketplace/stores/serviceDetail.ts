import type { ServiceComment, ServiceDetail } from '~/types/api'

export const useServiceDetailStore = defineStore('serviceDetail', {
  state: () => ({
    current: null as ServiceDetail | null,
    loading: false,
  }),
  actions: {
    async fetch(slug: string) {
      this.loading = true
      try {
        const { get } = useApi()
        const { data } = await get<{ data: ServiceDetail }>(`/marketplace/services/${slug}`)
        this.current = data
      } finally {
        this.loading = false
      }
    },

    applyVote(result: { my_vote: 1 | -1 | null; vote_up: number; vote_down: number }) {
      if (!this.current) return
      this.current.my_vote = result.my_vote
      this.current.vote_up = result.vote_up
      this.current.vote_down = result.vote_down
    },

    setBookmarked(bookmarked: boolean) {
      if (this.current) this.current.is_bookmarked = bookmarked
    },

    async postComment(body: string, parentId: string | null = null) {
      if (!this.current) return
      const { post } = useApi()
      const { data } = await post<{ data: ServiceComment }>(`/marketplace/services/${this.current.id}/comments`, {
        body,
        parent_id: parentId,
      })

      if (parentId) {
        const parent = this.current.comments.find((c) => c.id === parentId)
        if (parent) parent.replies.push(data)
      } else {
        this.current.comments.unshift(data)
      }
      this.current.comment_count += 1

      return data
    },
  },
})
