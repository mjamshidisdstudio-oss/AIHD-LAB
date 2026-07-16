<script setup lang="ts">
// design-reference/service-admin.dc.html — TAB: REVIEWS (comments & ratings).
// Deviations flagged in the PR:
// 1) The design derives a comment's sentiment badge from a mock 1-5
//    `rating` field (>=4 up, <=2 down, else neutral) with "Upvoted"/
//    "Downvoted" labels. Our schema stores CommentSentiment directly as
//    positive/neutral/negative, so this reads that enum straight and
//    labels it Positive/Neutral/Negative instead of conflating it with
//    the service-level vote_up/vote_down (a distinct real feature, shown
//    in the score card above using its own real up/down wording).
// 2) The design's "Reply" button has no handler (dead in the mock). The
//    prompt asks for real admin replies via parent_id, so it opens an
//    inline composer and posts to /admin/comments/{id}/reply, then
//    renders the resulting thread underneath the comment — a small
//    structural addition the flat mock data had no reason to include.
// 3) "Filter by version" counts are computed from the fetched comment set
//    (paginated at 20 server-side, matching every other admin list) rather
//    than a true global per-version count.
import { useServiceDetailStore } from '~/stores/serviceDetail'
import { useToastStore } from '~/stores/toast'
import type { AdminComment, CommentSentiment } from '~/types/api'

const detail = useServiceDetailStore()
const toast = useToastStore()
const api = useApi()

const comments = ref<AdminComment[]>([])
const versionFilter = ref<'all' | string>('all')
const replyDraft = ref<Record<string, string>>({})
const replyOpenFor = ref<string | null>(null)
const savingCommentId = ref<string | null>(null)

async function fetchComments() {
  if (!detail.service) return
  try {
    const res = await api.get<{ data: AdminComment[] }>(`/admin/services/${detail.service.id}/comments`)
    comments.value = res.data
  } catch {
    toast.show('Could not load comments.')
  }
}
watchEffect(() => {
  if (detail.service) fetchComments()
})

function versionLabel(versionId: string) {
  const v = detail.versions.find((ver) => ver.id === versionId)
  return v ? `v${v.version_no}` : versionId.slice(0, 8)
}

const filters = computed(() => {
  const all = { value: 'all' as const, label: 'All versions', count: comments.value.length }
  const ids = [...new Set(comments.value.map((c) => c.service_version_id))]
  const perVersion = ids.map((id) => ({
    value: id,
    label: `Version ${versionLabel(id)}`,
    count: comments.value.filter((c) => c.service_version_id === id).length,
  }))
  return [all, ...perVersion]
})

const filtered = computed(() =>
  versionFilter.value === 'all' ? comments.value : comments.value.filter((c) => c.service_version_id === versionFilter.value),
)
const publishedCount = computed(() => filtered.value.filter((c) => c.status === 'published').length)

const up = computed(() => detail.service?.vote_up ?? 0)
const down = computed(() => detail.service?.vote_down ?? 0)
const voteTotal = computed(() => up.value + down.value)
const votePct = computed(() => (voteTotal.value ? Math.round((up.value / voteTotal.value) * 100) : 0))

const SENTIMENT_META: Record<CommentSentiment, { label: string; color: string; bg: string }> = {
  positive: { label: 'Positive', color: '#16A34A', bg: '#E9FBF0' },
  negative: { label: 'Negative', color: '#D70D3E', bg: '#FDECEF' },
  neutral: { label: 'Neutral', color: '#7D7E80', bg: '#F0F1F4' },
}

async function toggleHide(comment: AdminComment) {
  savingCommentId.value = comment.id
  const next = comment.status === 'hidden' ? 'published' : 'hidden'
  try {
    await api.patch(`/admin/comments/${comment.id}`, { status: next })
    comment.status = next
  } catch {
    toast.show('Could not update this comment.')
  } finally {
    savingCommentId.value = null
  }
}

function toggleReply(commentId: string) {
  replyOpenFor.value = replyOpenFor.value === commentId ? null : commentId
}

async function sendReply(comment: AdminComment) {
  const body = (replyDraft.value[comment.id] ?? '').trim()
  if (!body) return
  savingCommentId.value = comment.id
  try {
    const res = await api.post<{ data: AdminComment }>(`/admin/comments/${comment.id}/reply`, { body })
    comment.replies.push(res.data)
    replyDraft.value[comment.id] = ''
    replyOpenFor.value = null
    toast.show('Reply posted.')
  } catch {
    toast.show('Could not post this reply.')
  } finally {
    savingCommentId.value = null
  }
}

function initial(userRef: string) {
  return (userRef || 'U').slice(-2)
}
</script>

<template>
  <div class="flex-1 overflow-auto bg-[#F6F7F9] p-8">
    <div class="mx-auto flex max-w-[760px] flex-col gap-[18px]">
      <div class="flex flex-wrap items-center gap-8 rounded-[22px] border border-[#ECECEE] bg-white p-[26px_28px] shadow-[0_1px_2px_rgba(133,151,171,.05)]">
        <div class="flex-none text-center">
          <div class="text-[48px] font-extrabold leading-none tracking-[-.02em] text-[#16A34A]">+{{ up - down }}</div>
          <div class="mt-2 text-[12px] font-semibold text-[#7D7E80]">net community score</div>
        </div>
        <div class="flex min-w-[240px] flex-1 flex-col gap-3">
          <div class="flex items-center gap-4">
            <div class="inline-flex items-center gap-1.5 text-[15px] font-bold text-[#16A34A]">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 4l8 12H4z" /></svg>
              {{ up.toLocaleString('en-US') }}
              <span class="text-[12.5px] font-medium text-[#7D7E80]">upvotes</span>
            </div>
            <div class="inline-flex items-center gap-1.5 text-[15px] font-bold text-[#D70D3E]">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 20L4 8h16z" /></svg>
              {{ down.toLocaleString('en-US') }}
              <span class="text-[12.5px] font-medium text-[#7D7E80]">downvotes</span>
            </div>
          </div>
          <div class="h-[9px] overflow-hidden rounded-full bg-[#FDECEF]">
            <div class="h-full rounded-full" :style="{ width: `${votePct}%`, background: 'linear-gradient(90deg,#16A34A,#22C55E)' }" />
          </div>
          <div class="text-[12px] font-bold text-[#16A34A]">{{ votePct }}% upvoted</div>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <span class="mr-0.5 text-[11.5px] font-semibold text-[#7D7E80]">Filter by version:</span>
        <button
          v-for="f in filters"
          :key="f.value"
          class="whitespace-nowrap rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold"
          :class="versionFilter === f.value ? 'border-[#5639E5] bg-[#F0EDFE] text-[#4628C9]' : 'border-[#D4D9E3] bg-white text-[#4B4C4D]'"
          @click="versionFilter = f.value"
        >
          {{ f.label }} · {{ f.count }}
        </button>
      </div>

      <div class="flex items-center justify-between">
        <span class="text-[15px] font-bold">User comments</span>
        <span class="text-[11.5px] text-[#7D7E80]">{{ publishedCount }} published of {{ filtered.length }}</span>
      </div>

      <div
        v-for="c in filtered"
        :key="c.id"
        class="rounded-2xl border border-[#ECECEE] bg-white p-[18px_20px] shadow-[0_1px_2px_rgba(133,151,171,.05)]"
        :class="c.status === 'hidden' ? 'opacity-60' : ''"
      >
        <div class="flex items-start gap-3">
          <div class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-[#F0EDFE] font-mono text-[13px] font-bold text-[#5639E5]">
            {{ initial(c.user_ref) }}
          </div>
          <div class="min-w-0 flex-1">
            <div class="mb-2 flex flex-wrap items-center gap-2">
              <span class="font-mono text-[12.5px] font-semibold">{{ c.user_ref }}</span>
              <span class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[10.5px] font-bold" :style="{ color: SENTIMENT_META[c.sentiment].color, background: SENTIMENT_META[c.sentiment].bg }">
                {{ SENTIMENT_META[c.sentiment].label }}
              </span>
              <span class="rounded-md bg-[#E8F8EE] px-2 py-0.5 text-[10.5px] font-bold text-[#168A40]">Version {{ versionLabel(c.service_version_id) }}</span>
              <span class="rounded-md px-2 py-0.5 text-[10.5px] font-bold" :class="c.status === 'hidden' ? 'bg-[#F2F2F2] text-[#7D7E80]' : 'bg-[#E8F8EE] text-[#168A40]'">
                {{ c.status === 'hidden' ? 'Hidden' : 'Published' }}
              </span>
              <span class="ml-auto font-mono text-[10.5px] text-[#969799]">{{ new Date(c.created_at).toLocaleDateString() }}</span>
            </div>
            <div class="text-[13.5px] leading-[1.8] text-[#19191A]">{{ c.body }}</div>
            <div class="mt-3 flex gap-2">
              <button
                class="h-8 rounded-lg border border-[#D4D9E3] bg-white px-3.5 text-[11.5px] font-semibold text-[#4B4C4D] disabled:opacity-60"
                :disabled="savingCommentId === c.id"
                @click="toggleHide(c)"
              >
                {{ c.status === 'hidden' ? 'Publish' : 'Hide' }}
              </button>
              <button class="h-8 rounded-lg border border-[#D4D9E3] bg-white px-3.5 text-[11.5px] font-semibold text-[#4B4C4D]" @click="toggleReply(c.id)">
                Reply
              </button>
            </div>

            <div v-if="c.replies.length > 0" class="mt-3 flex flex-col gap-2 border-l-2 border-[#F0EDFE] pl-3">
              <div v-for="r in c.replies" :key="r.id" class="rounded-lg bg-[#FAFAFA] p-2.5">
                <div class="mb-1 flex items-center gap-2">
                  <span class="rounded-md bg-[#F0EDFE] px-1.5 py-0.5 text-[10px] font-bold text-[#5639E5]">Admin</span>
                  <span class="font-mono text-[10.5px] text-[#969799]">{{ new Date(r.created_at).toLocaleDateString() }}</span>
                </div>
                <div class="text-[12.5px] text-[#4B4C4D]">{{ r.body }}</div>
              </div>
            </div>

            <div v-if="replyOpenFor === c.id" class="mt-3 flex flex-col gap-2">
              <textarea
                v-model="replyDraft[c.id]"
                rows="2"
                placeholder="Write a reply…"
                class="rounded-lg border border-[#DCE0E7] p-2.5 text-[12.5px]"
              />
              <button
                class="self-start rounded-full bg-[#5639E5] px-3.5 py-1.5 text-[11.5px] font-semibold text-white disabled:opacity-60"
                :disabled="savingCommentId === c.id || !(replyDraft[c.id] ?? '').trim()"
                @click="sendReply(c)"
              >
                Send
              </button>
            </div>
          </div>
        </div>
      </div>
      <div v-if="filtered.length === 0" class="rounded-[16px] p-[50px] text-center text-[14px] text-[#969799]">No comments for this service yet.</div>
    </div>
  </div>
</template>
