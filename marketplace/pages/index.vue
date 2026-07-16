<script setup lang="ts">
import { useCatalogStore } from '~/stores/catalog'
import { categoryLabel } from '~/composables/useCategoryStyle'

const catalog = useCatalogStore()

onMounted(() => {
  if (!catalog.loaded) catalog.fetchList()
})

const sortTabs: Array<{ key: 'hot' | 'top' | 'new'; label: string }> = [
  { key: 'hot', label: 'Hot this week' },
  { key: 'top', label: 'Most loved' },
  { key: 'new', label: 'New' },
]

const categories = computed(() => ['all', ...catalog.categories])
</script>

<template>
  <main class="mx-auto max-w-[1280px] px-[26px] py-9 pb-[90px]">
    <div
      class="relative mb-[34px] overflow-hidden rounded-[26px] border border-[#ECE7FB] p-[38px_40px]"
      style="background: linear-gradient(120deg, #f4f1ff 0%, #fbf6ff 46%, #fdf3fa 100%)"
    >
      <div class="relative flex flex-wrap items-center gap-[38px]">
        <div class="relative flex h-[104px] w-[104px] flex-none items-center justify-center rounded-[28px]" style="background: linear-gradient(150deg, #6e4cf2, #5639e5); box-shadow: 0 18px 34px -14px rgba(86, 57, 229, .6)">
          <svg width="56" height="56" viewBox="0 0 100 100" fill="none">
            <path d="M50 16 C52 32 59 39 74 41 C59 43 52 50 50 66 C48 50 41 43 26 41 C41 39 48 32 50 16 Z" fill="#fff" />
          </svg>
        </div>
        <div class="min-w-[280px] flex-1">
          <h1 class="mb-3 text-[44px] font-extrabold leading-[1.1] tracking-tight text-[#19191A]">
            New real-estate tools,<br>fresh from the lab.
          </h1>
          <p class="max-w-[520px] text-base leading-relaxed text-[#54555A]">
            Try our newest tools while they're in beta. The ones you love ship to the main product.
          </p>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 items-start gap-7 lg:grid-cols-[minmax(0,1fr)_320px]">
      <div class="min-w-0">
        <div class="mb-5 flex flex-wrap items-center gap-3.5">
          <div class="me-auto flex items-center">
            <button
              v-for="t in sortTabs"
              :key="t.key"
              class="me-[22px] border-0 border-b-2 bg-none py-[7px] text-sm"
              :class="catalog.sort === t.key ? 'border-[#7F56D9] font-bold text-[#19191A]' : 'border-transparent font-semibold text-[#9A9BA0]'"
              @click="catalog.sort = t.key"
            >
              {{ t.label }}
            </button>
          </div>
          <div class="relative">
            <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#9A9BA0" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="M21 21l-3.5-3.5" /></svg>
            <input
              v-model="catalog.query"
              placeholder="Search…"
              class="h-[38px] w-[190px] rounded-[10px] border border-transparent bg-[#F4F5F7] pl-9 pr-3.5 text-[13px]"
            >
          </div>
          <button
            class="inline-flex h-[38px] items-center gap-[7px] rounded-full border px-[15px] text-[13px] font-semibold"
            :class="catalog.savedOnly ? 'border-transparent bg-[#F1EEFE] text-[#5B3FD6]' : 'border-[#ECEEF1] bg-white text-[#6B6C6E]'"
            @click="catalog.savedOnly = !catalog.savedOnly"
          >
            <svg width="15" height="15" viewBox="0 0 24 24" :fill="catalog.savedOnly ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" /></svg>
            Saved <span class="font-extrabold">{{ catalog.services.filter((s) => s.is_bookmarked).length }}</span>
          </button>
        </div>

        <div class="mb-[22px] flex flex-wrap gap-2">
          <button
            v-for="c in categories"
            :key="c"
            class="rounded-full border px-[14px] py-[7px] text-[12.5px] font-semibold"
            :class="catalog.category === c ? 'border-transparent bg-[#F1EEFE] text-[#5B3FD6]' : 'border-[#ECEEF1] bg-white text-[#6B6C6E]'"
            @click="catalog.category = c"
          >
            {{ c === 'all' ? 'All' : categoryLabel(c) }}
          </button>
        </div>

        <div v-if="catalog.loading" class="p-16 text-center text-sm text-[#969799]">Loading services…</div>

        <template v-else-if="catalog.filtered.length">
          <div v-if="catalog.layout === 'board'" class="flex flex-col gap-3">
            <ServiceCard v-for="(s, i) in catalog.filtered" :key="s.id" :service="s" layout="board" :rank="i" />
          </div>
          <div v-else class="grid grid-cols-[repeat(auto-fill,minmax(320px,1fr))] gap-[18px]">
            <ServiceCard v-for="s in catalog.filtered" :key="s.id" :service="s" layout="grid" />
          </div>
        </template>

        <div v-else class="p-[70px] text-center text-sm text-[#969799]">
          {{ catalog.savedOnly ? 'No bookmarks yet. Tap the bookmark icon on a service to save it here.' : 'No services found.' }}
        </div>
      </div>
    </div>
  </main>
</template>
