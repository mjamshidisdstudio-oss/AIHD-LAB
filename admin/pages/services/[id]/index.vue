<script setup lang="ts">
import { useServiceDetailStore } from '~/stores/serviceDetail'

const route = useRoute()
const router = useRouter()
const detail = useServiceDetailStore()

const initialTab = typeof route.query.tab === 'string' ? route.query.tab : 'overview'
const tab = ref(initialTab)

await useAsyncData(`admin-service-${route.params.id}`, () =>
  detail.load(route.params.id as string, typeof route.query.v === 'string' ? route.query.v : undefined),
)

// "logs" needs the switcher too: admin_preview targets detail.selectedVersion,
// and without the bar visible there's no way to see or change which version
// the "Admin preview" button in that tab is about to run.
const showVersionBar = computed(() => ['overview', 'inputs', 'integration', 'outputs', 'logs'].includes(tab.value))

// Keep the URL in sync with tab/version so a reload lands back where the
// operator was — a draft mid-edit, not always the published version.
watch(tab, (value) => {
  router.replace({ query: { ...route.query, tab: value } })
})
watch(
  () => detail.selectedVersionId,
  (value) => {
    if (value) router.replace({ query: { ...route.query, v: value } })
  },
)
</script>

<template>
  <div style="display: flex; flex-direction: column; height: 100vh; overflow: hidden">
    <EditorTopBar />
    <EditorTabBar v-model="tab" />
    <EditorVersionSwitcher v-if="showVersionBar" />

    <EditorOverviewTab v-if="tab === 'overview'" />
    <EditorInputsTab v-else-if="tab === 'inputs'" />
    <EditorIntegrationTab v-else-if="tab === 'integration'" />
    <EditorOutputsTab v-else-if="tab === 'outputs'" />
    <EditorLogsTab v-else-if="tab === 'logs'" />
    <EditorReviewsTab v-else-if="tab === 'reviews'" />
  </div>
</template>
