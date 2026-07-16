import type { Service, ServiceVersion } from '~/types/api'

export const useServiceDetailStore = defineStore('serviceDetail', {
  state: () => ({
    service: null as Service | null,
    versions: [] as ServiceVersion[],
    selectedVersionId: null as string | null,
  }),
  getters: {
    selectedVersion: (state): ServiceVersion | null =>
      state.versions.find((v) => v.id === state.selectedVersionId) ?? null,
    sortedVersions: (state): ServiceVersion[] =>
      [...state.versions].sort((a, b) => b.version_no - a.version_no),
  },
  actions: {
    async load(serviceId: string, preferredVersionId?: string) {
      const api = useApi()
      const [serviceRes, versionsRes] = await Promise.all([
        api.get<{ data: Service }>(`/admin/services/${serviceId}`),
        api.get<{ data: ServiceVersion[] }>(`/admin/services/${serviceId}/versions`),
      ])
      this.service = serviceRes.data
      this.versions = versionsRes.data

      // A reload should keep showing whichever version was open (e.g. a
      // draft mid-edit), not silently fall back to the published one — the
      // page reads/writes this via a `v=` query param.
      const preferred = preferredVersionId && this.versions.some((v) => v.id === preferredVersionId)
        ? preferredVersionId
        : null
      const current = this.versions.find((v) => v.id === this.service?.current_version_id)
      const latestDraft = [...this.versions].sort((a, b) => b.version_no - a.version_no)[0]
      this.selectedVersionId = preferred ?? current?.id ?? latestDraft?.id ?? null
    },
    async reloadVersions() {
      if (!this.service) return
      const api = useApi()
      const versionsRes = await api.get<{ data: ServiceVersion[] }>(`/admin/services/${this.service.id}/versions`)
      this.versions = versionsRes.data
    },
    async reloadVersion(versionId: string) {
      const api = useApi()
      const res = await api.get<{ data: ServiceVersion }>(`/admin/versions/${versionId}`)
      const index = this.versions.findIndex((v) => v.id === versionId)
      if (index !== -1) this.versions[index] = res.data
      else this.versions.push(res.data)
    },
    async reloadService() {
      if (!this.service) return
      const api = useApi()
      const res = await api.get<{ data: Service }>(`/admin/services/${this.service.id}`)
      this.service = res.data
    },
    selectVersion(versionId: string) {
      this.selectedVersionId = versionId
    },
    /**
     * The version list (GET /services/{service}/versions) doesn't eager-load
     * inputs/outputs/waiting_texts — only GET /versions/{version} does. Tabs
     * that need that content call this before reading them.
     */
    async ensureVersionDetailLoaded(versionId: string) {
      const version = this.versions.find((v) => v.id === versionId)
      if (version && version.inputs !== undefined && version.outputs !== undefined && version.waiting_texts !== undefined) {
        return
      }
      await this.reloadVersion(versionId)
    },
  },
})
