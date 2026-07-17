<script setup lang="ts">
import { useServiceDetailStore } from '~/stores/serviceDetail'
import { useCatalogStore } from '~/stores/catalog'
import { useCategoryColor, categoryLabel } from '~/composables/useCategoryStyle'

const route = useRoute()
const router = useRouter()
const detail = useServiceDetailStore()
const catalog = useCatalogStore()

const slug = computed(() => String(route.params.slug))

await useAsyncData(`service-${slug.value}`, () => detail.fetch(slug.value), { watch: [slug] })

const service = computed(() => detail.current)
const catColor = computed(() => (service.value ? useCategoryColor(service.value.category) : '#5639E5'))
const costLabel = computed(() => (service.value?.is_free ? 'Free' : `${service.value?.coin_cost ?? 0} credits`))

const rankedByVotes = computed(() => [...catalog.services].sort((a, b) => b.vote_up - a.vote_up))
const rankIndex = computed(() => rankedByVotes.value.findIndex((s) => s.id === service.value?.id))

function goRelative(delta: number) {
  const list = rankedByVotes.value
  if (!list.length || rankIndex.value === -1) return
  const next = list[(rankIndex.value + delta + list.length) % list.length]
  router.push(`/services/${next.slug}`)
}

function vote(value: 1 | -1) {
  if (!service.value) return
  useApi()
    .post<{ my_vote: 1 | -1 | null; vote_up: number; vote_down: number }>(`/marketplace/services/${service.value.id}/vote`, { value })
    .then((r) => {
      detail.applyVote(r)
      catalog.applyVote(service.value!.id, r)
    })
}

function bookmark() {
  if (!service.value) return
  catalog.toggleBookmark(service.value.id).then((b) => detail.setBookmarked(b))
}

function useService() {
  if (!service.value) return
  if (service.value.kind === 'external') {
    catalog.logExternalClick(service.value.id).catch(() => {})
    window.open(service.value.external_url ?? 'about:blank', '_blank', 'noopener')

    return
  }
  router.push(`/services/${service.value.slug}/use`)
}

const commentText = ref('')
const replyTo = ref<string | null>(null)
const replyText = ref('')

function postComment() {
  const body = commentText.value.trim()
  if (!body) return
  detail.postComment(body).then(() => {
    commentText.value = ''
  })
}

function postReply(parentId: string) {
  const body = replyText.value.trim()
  if (!body) return
  detail.postComment(body, parentId).then(() => {
    replyText.value = ''
    replyTo.value = null
  })
}
</script>

<template>
  <main v-if="service" class="mx-auto max-w-[1180px] px-[26px] py-[26px] pb-[60px]">
    <div class="grid grid-cols-1 items-start gap-[34px] lg:grid-cols-[minmax(0,1fr)_340px]">
      <div class="min-w-0">
        <button class="mb-4 inline-flex h-[34px] items-center gap-1.5 rounded-full border border-[#E1E4E8] bg-white px-[13px] text-[12.5px] font-semibold text-[#4B4C4D]" @click="router.push('/')">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6" /></svg>
          Back to marketplace
        </button>

        <div class="mb-[22px] flex items-start gap-4">
          <div class="h-16 w-16 flex-none rounded-2xl bg-[#EEF0F4] bg-cover bg-center" :style="{ backgroundImage: service.image_url ? `url(${service.image_url})` : undefined }" />
          <div class="min-w-0 flex-1">
            <div class="mb-1 flex flex-wrap items-center gap-[9px]">
              <h1 class="text-[26px] font-extrabold leading-tight tracking-tight">{{ service.name }}</h1>
              <span v-if="service.trending_rank !== null" class="inline-flex items-center gap-1 whitespace-nowrap rounded-full bg-[#FFF1E8] px-[11px] py-1 text-xs font-bold text-[#E8590C]">Trending #{{ service.trending_rank }}</span>
              <span v-if="service.is_free" class="inline-flex items-center whitespace-nowrap rounded-full bg-[#E9FBF0] px-[11px] py-1 text-[11.5px] font-extrabold uppercase tracking-wide text-[#16A34A]">Free</span>
              <span v-if="service.kind === 'external'" class="inline-flex items-center gap-1 whitespace-nowrap rounded-full bg-[#FEF3E2] px-[11px] py-1 text-[11.5px] font-extrabold uppercase tracking-wide text-[#B45309]">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17L17 7M9 7h8v8" /></svg>External
              </span>
            </div>
            <p class="mb-[9px] text-[15px] leading-snug text-[#4B4C4D]">{{ service.tagline }}</p>
            <div class="flex flex-wrap items-center gap-3.5">
              <span class="inline-flex items-center rounded-full px-[13px] py-[5px] text-xs font-bold" :style="{ background: catColor + '18', color: catColor }">{{ categoryLabel(service.category) }}</span>
            </div>
          </div>
        </div>

        <div class="mb-[22px] flex gap-1.5 overflow-x-auto border-b border-[#ECEEF1]">
          <a href="#d-overview" class="whitespace-nowrap border-b-2 border-[#5639E5] px-[15px] py-[11px] text-[13.5px] font-bold text-[#19191A]">About</a>
          <a v-if="service.gallery.length > 0" href="#d-gallery" class="whitespace-nowrap border-b-2 border-transparent px-[15px] py-[11px] text-[13.5px] font-semibold text-[#7D7E80]">Gallery</a>
          <a v-if="service.before_image_url && service.after_image_url" href="#d-beforeafter" class="whitespace-nowrap border-b-2 border-transparent px-[15px] py-[11px] text-[13.5px] font-semibold text-[#7D7E80]">Before &amp; after</a>
          <a href="#d-reviews" class="whitespace-nowrap border-b-2 border-transparent px-[15px] py-[11px] text-[13.5px] font-semibold text-[#7D7E80]">Reviews</a>
        </div>

        <section id="d-overview" class="mb-[34px]">
          <h2 class="mb-3 text-lg font-extrabold">About this service</h2>
          <p class="text-[15px] leading-loose text-[#4B4C4D]">{{ service.description }}</p>
        </section>

        <div v-if="service.gallery.length > 0" id="d-gallery" class="mb-[34px] grid grid-cols-3 gap-3">
          <div v-for="(src, i) in service.gallery" :key="i" class="aspect-square overflow-hidden rounded-[14px] bg-[#EEF0F4] bg-cover bg-center" :style="{ backgroundImage: `url(${src})` }" />
        </div>

        <section v-if="service.before_image_url && service.after_image_url" id="d-beforeafter" class="mb-[34px]">
          <h2 class="mb-3.5 text-lg font-extrabold">Before &amp; after</h2>
          <div class="grid grid-cols-2 gap-3">
            <div class="relative aspect-[4/3] overflow-hidden rounded-[14px] bg-[#EEF0F4] bg-cover bg-center" :style="{ backgroundImage: `url(${service.before_image_url})` }">
              <div class="absolute left-3 top-3 rounded-full bg-[rgba(25,25,26,.72)] px-[13px] py-[5px] text-xs font-bold text-white">Before</div>
            </div>
            <div class="relative aspect-[4/3] overflow-hidden rounded-[14px] bg-[#EEF0F4] bg-cover bg-center" :style="{ backgroundImage: `url(${service.after_image_url})` }">
              <div class="absolute left-3 top-3 rounded-full bg-[#5639E5] px-[13px] py-[5px] text-xs font-bold text-white">After</div>
            </div>
          </div>
        </section>

        <section id="d-reviews">
          <h2 class="mb-4 text-lg font-extrabold">Discussion <span class="font-semibold text-[#969799]">({{ service.comment_count }})</span></h2>

          <div class="mb-[22px] flex gap-3">
            <div class="h-10 w-10 flex-none rounded-full bg-[#5639E5]" />
            <div class="flex-1">
              <textarea
                v-model="commentText"
                rows="2"
                placeholder="Share your experience with this service…"
                class="w-full rounded-[14px] border border-[#E1E4E8] p-[12px_15px] text-sm leading-relaxed"
              />
              <div class="mt-2 flex justify-end">
                <button class="h-10 rounded-full border-0 bg-[#5639E5] px-5 text-[13.5px] font-bold text-white" @click="postComment">Post comment</button>
              </div>
            </div>
          </div>

          <div v-for="c in service.comments" :key="c.id" class="mb-[18px] flex gap-3 border-b border-[#F0F1F4] pb-[18px]">
            <div class="h-10 w-10 flex-none rounded-full bg-[#0090F8]" />
            <div class="min-w-0 flex-1">
              <div class="mb-1 flex items-center gap-2">
                <span class="text-[13.5px] font-bold">{{ c.user_ref }}</span>
                <span class="text-[11.5px] text-[#B8BCC0]">{{ new Date(c.created_at).toLocaleDateString() }}</span>
              </div>
              <div class="mb-2.5 text-sm leading-relaxed text-[#323233]">{{ c.body }}</div>
              <button class="border-0 bg-none p-0 text-[12.5px] font-semibold text-[#7D7E80]" @click="replyTo = replyTo === c.id ? null : c.id">
                {{ replyTo === c.id ? 'Close' : 'Reply' }}
              </button>

              <div v-if="c.replies.length" class="ms-4 mt-1.5 border-s-2 border-[#F0F1F4] ps-4">
                <div v-for="r in c.replies" :key="r.id" class="mt-3.5 flex gap-2.5">
                  <div class="h-8 w-8 flex-none rounded-full bg-[#16A34A]" />
                  <div class="min-w-0 flex-1">
                    <div class="mb-0.5 flex items-center gap-2">
                      <span class="text-[13px] font-bold">{{ r.user_ref }}</span>
                    </div>
                    <div class="text-[13.5px] leading-relaxed text-[#323233]">{{ r.body }}</div>
                  </div>
                </div>
              </div>

              <div v-if="replyTo === c.id" class="mt-3.5 flex gap-2.5">
                <div class="h-8 w-8 flex-none rounded-full bg-[#5639E5]" />
                <div class="flex-1">
                  <textarea v-model="replyText" rows="2" placeholder="Write a reply…" class="w-full rounded-xl border border-[#E1E4E8] p-[10px_13px] text-[13.5px] leading-relaxed" />
                  <div class="mt-1.5 flex justify-end gap-2">
                    <button class="h-[34px] rounded-full border border-[#D4D9E3] bg-white px-3.5 text-[12.5px] font-semibold text-[#4B4C4D]" @click="replyTo = null; replyText = ''">Cancel</button>
                    <button class="h-[34px] rounded-full border-0 bg-[#5639E5] px-4 text-[12.5px] font-bold text-white" @click="postReply(c.id)">Reply</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>

      <aside class="sticky top-[84px] flex flex-col gap-4">
        <div class="rounded-[20px] border border-[#ECEEF1] bg-white p-[18px]">
          <div class="mb-4 flex items-center justify-between">
            <div>
              <div class="text-[22px] font-extrabold leading-none">{{ service.trending_rank !== null ? `#${service.trending_rank}` : '—' }}</div>
              <div class="mt-[3px] text-[11.5px] font-semibold text-[#969799]">This week's rank</div>
            </div>
            <div class="flex gap-1.5">
              <button title="Previous" class="flex h-8 w-8 items-center justify-center rounded-[9px] border border-[#E1E4E8] bg-white text-[#4B4C4D]" @click="goRelative(-1)">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6" /></svg>
              </button>
              <button title="Next" class="flex h-8 w-8 items-center justify-center rounded-[9px] border border-[#E1E4E8] bg-white text-[#4B4C4D]" @click="goRelative(1)">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6" /></svg>
              </button>
            </div>
          </div>
          <button
            class="mb-2.5 inline-flex h-[50px] w-full items-center justify-center gap-2 rounded-full border-0 bg-[#5639E5] text-[15.5px] font-bold text-white"
            style="box-shadow: 0 12px 26px -12px rgba(86, 57, 229, .6)"
            @click="useService"
          >
            <svg v-if="service.kind === 'external'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17L17 7M9 7h8v8" /></svg>
            <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="#fff"><path d="M13 2L4.5 13.5H11l-1 8.5 8.5-11.5H12z" /></svg>
            {{ service.kind === 'external' ? 'Open on partner site' : 'Use this service' }}
          </button>
          <div class="flex gap-2.5">
            <button
              class="flex flex-1 items-center justify-center gap-1.5 rounded-xl border text-sm font-bold"
              style="height: 46px"
              :class="service.my_vote === 1 ? 'border-[#16A34A] bg-[#E9FBF0] text-[#16A34A]' : 'border-[#E7EAEF] bg-white text-[#4B4C4D]'"
              @click="vote(1)"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 7l7 11H5z" /></svg>{{ service.vote_up }}
            </button>
            <button
              class="flex flex-1 items-center justify-center gap-1.5 rounded-xl border text-sm font-bold"
              style="height: 46px"
              :class="service.my_vote === -1 ? 'border-[#D92D2D] bg-[#FDECEC] text-[#D92D2D]' : 'border-[#E7EAEF] bg-white text-[#4B4C4D]'"
              @click="vote(-1)"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 17L5 6h14z" /></svg>{{ service.vote_down }}
            </button>
          </div>
        </div>

        <div class="rounded-[20px] border border-[#ECEEF1] bg-white p-2">
          <button class="flex w-full items-center gap-[11px] rounded-xl px-3 py-[11px] text-left text-[13.5px] font-semibold text-[#323233]" @click="navigator.clipboard?.writeText(location.href)">
            <span class="flex text-[#5639E5]"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8" /><path d="M16 6l-4-4-4 4" /><path d="M12 2v13" /></svg></span>
            Share
          </button>
          <button class="flex w-full items-center gap-[11px] rounded-xl px-3 py-[11px] text-left text-[13.5px] font-semibold text-[#323233]" @click="bookmark">
            <span class="flex text-[#5639E5]"><svg width="18" height="18" viewBox="0 0 24 24" :fill="service.is_bookmarked ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" /></svg></span>
            {{ service.is_bookmarked ? 'Bookmarked' : 'Bookmark this service' }}
          </button>
        </div>

        <div class="rounded-[20px] border border-[#ECEEF1] bg-white p-[18px]">
          <div class="mb-3.5 text-sm font-extrabold">Service info</div>
          <div class="flex flex-col gap-3.5">
            <div class="flex items-center justify-between"><span class="text-[13px] text-[#969799]">Category</span><span class="text-[13px] font-bold">{{ categoryLabel(service.category) }}</span></div>
            <div class="flex items-center justify-between"><span class="text-[13px] text-[#969799]">Cost per run</span><span class="text-[13px] font-bold" :class="service.is_free ? 'text-[#16A34A]' : 'text-[#5639E5]'">{{ costLabel }}</span></div>
            <div v-if="service.avg_latency_ms" class="flex items-center justify-between"><span class="text-[13px] text-[#969799]">Avg. speed</span><span class="text-[13px] font-bold">~{{ Math.round(service.avg_latency_ms / 1000) }}s</span></div>
            <div class="flex items-center justify-between"><span class="text-[13px] text-[#969799]">Published</span><span class="text-[13px] font-bold">{{ service.published_at ? new Date(service.published_at).toLocaleDateString() : '—' }}</span></div>
          </div>
          <div v-if="service.kind === 'external'" class="mt-3.5 flex items-start gap-2 rounded-xl border border-[#F3E4CC] bg-[#FEF9F0] p-[11px_13px]">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#B45309" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-px flex-none"><path d="M7 17L17 7M9 7h8v8" /></svg>
            <span class="text-[12.5px] leading-relaxed text-[#8A5A12]">This service runs on a partner site and opens in a new tab.</span>
          </div>
        </div>

        <div v-if="service.similar.length" class="rounded-[20px] border border-[#ECEEF1] bg-white p-[16px_16px_8px]">
          <div class="mb-1.5 px-0.5 text-sm font-extrabold">Similar services</div>
          <div class="flex flex-col">
            <NuxtLink v-for="s in service.similar" :key="s.id" :to="`/services/${s.slug}`" class="flex items-center gap-[11px] rounded-xl px-1.5 py-2.5">
              <div class="h-10 w-10 flex-none rounded-[11px] bg-[#EEF0F4] bg-cover bg-center" :style="{ backgroundImage: s.image_url ? `url(${s.image_url})` : undefined }" />
              <div class="min-w-0 flex-1">
                <div class="truncate text-[13.5px] font-bold">{{ s.name }}</div>
                <div class="text-xs text-[#969799]">{{ s.coin_cost ?? 0 }} credits</div>
              </div>
            </NuxtLink>
          </div>
        </div>
      </aside>
    </div>
  </main>
</template>
