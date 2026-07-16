<script setup lang="ts">
import type { ServiceCard as ServiceCardType } from '~/types/api'
import { useCatalogStore } from '~/stores/catalog'
import { useCategoryColor, categoryLabel } from '~/composables/useCategoryStyle'

const props = defineProps<{
  service: ServiceCardType
  layout: 'board' | 'grid'
  rank?: number
}>()

const catalog = useCatalogStore()
const router = useRouter()

const catColor = computed(() => useCategoryColor(props.service.category))
const isExternal = computed(() => props.service.kind === 'external')
const costLabel = computed(() => (props.service.is_free ? 'Free' : `${props.service.coin_cost ?? 0} credits`))
const publishedRel = computed(() => relTime(props.service.published_at ?? props.service.created_at))

function relTime(iso: string): string {
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000)
  if (days <= 1) return 'today'
  if (days < 7) return `${days} days ago`
  if (days < 14) return 'a week ago'
  if (days < 30) return `${Math.round(days / 7)} weeks ago`
  if (days < 60) return 'a month ago'
  if (days < 365) return `${Math.round(days / 30)} months ago`
  const y = Math.round(days / 365)

  return y > 1 ? `${y} years ago` : 'a year ago'
}

function open() {
  if (isExternal.value) {
    catalog.logExternalClick(props.service.id).catch(() => {})
    window.open(props.service.external_url ?? 'about:blank', '_blank', 'noopener')

    return
  }
  router.push(`/services/${props.service.slug}`)
}

function bookmark(e: Event) {
  e.stopPropagation()
  catalog.toggleBookmark(props.service.id).catch(() => {})
}

function vote(e: Event, value: 1 | -1) {
  e.stopPropagation()
  catalog.vote(props.service.id, value).catch(() => {})
}
</script>

<template>
  <div
    v-if="layout === 'board'"
    class="flex cursor-pointer items-center gap-[13px] rounded-[18px] border bg-white p-[14px_16px] transition hover:-translate-y-px hover:shadow-[0_8px_22px_-16px_rgba(133,151,171,.55)]"
    :class="isExternal ? 'border-[#F3E4CC]' : 'border-[#F0F1F4]'"
    @click="open"
  >
    <div class="w-6 text-center text-[15px] font-bold" :class="(rank ?? 99) < 3 ? 'text-[#7F56D9]' : 'text-[#C5C8CE]'">{{ (rank ?? 0) + 1 }}</div>
    <div class="h-[52px] w-[52px] flex-none rounded-2xl bg-[#EEF0F4] bg-cover bg-center" :style="{ backgroundImage: service.image_url ? `url(${service.image_url})` : undefined }" />
    <div class="min-w-0 flex-1">
      <div class="mb-[3px] flex min-w-0 items-center gap-2">
        <span class="truncate text-[15.5px] font-bold tracking-tight">{{ service.name }}</span>
      </div>
      <div class="mb-2 truncate text-[13px] text-[#7D7E80]">{{ service.tagline }}</div>
      <div class="flex flex-wrap items-center gap-3">
        <span class="inline-flex flex-none items-center rounded-full px-[11px] py-1 text-[11.5px] font-bold" :style="{ background: catColor + '18', color: catColor }">{{ categoryLabel(service.category) }}</span>
        <span class="inline-flex items-center gap-1 text-[12.5px] text-[#9A9BA0]">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
          {{ publishedRel }}
        </span>
        <span v-if="!service.is_free" class="inline-flex items-center gap-[5px] text-[12.5px] font-bold text-[#6B6C6E]">{{ costLabel }}</span>
        <span v-else class="inline-flex items-center gap-1 rounded-full bg-[#E9FBF0] px-[9px] py-[3px] text-[11.5px] font-extrabold uppercase tracking-wide text-[#16A34A]">Free</span>
      </div>
    </div>
    <button
      title="Bookmark"
      class="flex h-[34px] w-[34px] flex-none items-center justify-center rounded-[10px] border"
      :class="service.is_bookmarked ? 'border-[#E4DAFB] bg-[#F4F0FE] text-[#7F56D9]' : 'border-[#ECEEF1] bg-white text-[#B0B2B6]'"
      @click="bookmark"
    >
      <svg width="16" height="16" viewBox="0 0 24 24" :fill="service.is_bookmarked ? '#7F56D9' : 'none'" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" /></svg>
    </button>
    <div class="flex items-center gap-2">
      <button
        title="Upvote"
        class="inline-flex h-8 items-center gap-[5px] rounded-[9px] border px-[11px] text-[12.5px] font-bold"
        :class="service.my_vote === 1 ? 'border-[#16A34A] bg-[#E9FBF0] text-[#16A34A]' : 'border-[#E7EAEF] bg-white text-[#7D7E80]'"
        @click="vote($event, 1)"
      >
        <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M12 7l7 11H5z" /></svg>{{ service.vote_up }}
      </button>
      <button
        title="Downvote"
        class="inline-flex h-8 items-center gap-[5px] rounded-[9px] border px-[11px] text-[12.5px] font-bold"
        :class="service.my_vote === -1 ? 'border-[#D92D2D] bg-[#FDECEC] text-[#D92D2D]' : 'border-[#E7EAEF] bg-white text-[#7D7E80]'"
        @click="vote($event, -1)"
      >
        <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M12 17L5 6h14z" /></svg>{{ service.vote_down }}
      </button>
    </div>
  </div>

  <div
    v-else
    class="flex cursor-pointer flex-col overflow-hidden rounded-[20px] border border-[#F0F1F4] bg-white transition hover:-translate-y-0.5 hover:border-[#E4DAFB] hover:shadow-[0_14px_32px_-22px_rgba(133,151,171,.6)]"
    @click="open"
  >
    <div class="relative h-[120px] bg-[#EEF0F4] bg-cover bg-center" :style="{ backgroundImage: service.image_url ? `url(${service.image_url})` : undefined }">
      <button
        title="Bookmark"
        class="absolute right-[10px] top-[10px] flex h-[30px] w-[30px] items-center justify-center rounded-[9px]"
        :class="service.is_bookmarked ? 'bg-white/90 text-[#5639E5]' : 'bg-white/85 text-[#7D7E80]'"
        @click="bookmark"
      >
        <svg width="15" height="15" viewBox="0 0 24 24" :fill="service.is_bookmarked ? '#7F56D9' : 'none'" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" /></svg>
      </button>
    </div>
    <div class="flex flex-1 flex-col p-[16px_18px_18px]">
      <div class="mb-1.5 flex items-center justify-between gap-2.5">
        <span class="text-[16.5px] font-bold tracking-tight">{{ service.name }}</span>
        <span class="inline-flex flex-none items-center rounded-full px-[11px] py-1 text-[11.5px] font-bold" :style="{ background: catColor + '18', color: catColor }">{{ categoryLabel(service.category) }}</span>
      </div>
      <div class="mb-3.5 min-h-[42px] text-[13px] leading-relaxed text-[#7D7E80]">{{ service.tagline }}</div>
      <div class="mb-3.5 flex items-center gap-3">
        <span class="inline-flex items-center gap-1 text-[12.5px] text-[#9A9BA0]">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
          {{ publishedRel }}
        </span>
        <span v-if="!service.is_free" class="inline-flex items-center gap-[5px] text-[12.5px] font-bold text-[#6B6C6E]">{{ costLabel }}</span>
        <span v-else class="inline-flex items-center gap-1 rounded-full bg-[#E9FBF0] px-[9px] py-[3px] text-[11.5px] font-extrabold uppercase tracking-wide text-[#16A34A]">Free</span>
      </div>
      <div class="flex items-center gap-2 border-t border-[#F0F1F4] pt-3">
        <button
          title="Upvote"
          class="inline-flex h-8 items-center gap-[5px] rounded-[9px] border px-[11px] text-[12.5px] font-bold"
          :class="service.my_vote === 1 ? 'border-[#16A34A] bg-[#E9FBF0] text-[#16A34A]' : 'border-[#E7EAEF] bg-white text-[#7D7E80]'"
          @click="vote($event, 1)"
        >
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 7l7 11H5z" /></svg>{{ service.vote_up }}
        </button>
        <button
          title="Downvote"
          class="inline-flex h-8 items-center gap-[5px] rounded-[9px] border px-[11px] text-[12.5px] font-bold"
          :class="service.my_vote === -1 ? 'border-[#D92D2D] bg-[#FDECEC] text-[#D92D2D]' : 'border-[#E7EAEF] bg-white text-[#7D7E80]'"
          @click="vote($event, -1)"
        >
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 17L5 6h14z" /></svg>{{ service.vote_down }}
        </button>
      </div>
    </div>
  </div>
</template>
