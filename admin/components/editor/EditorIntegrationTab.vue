<script setup lang="ts">
// design-reference/service-admin.dc.html lines 982-1216 (Integration/Exec tab).
// Deviates from the design in two ways, both flagged in the PR:
//   1. The design shows ONE secret field (svcKey/svcKeyHash) — the real
//      two-secret model (service_secret + webhook_signing_key) needs two.
//   2. coin_cost/regenerate_limit/response_timeout_s/get_interval_s/
//      max_get_attempts/post_url/get_url all live on the VERSION, not the
//      service, so they're edited here per-version rather than in Overview.
import { useServiceDetailStore } from '~/stores/serviceDetail'
import { useToastStore } from '~/stores/toast'

const detail = useServiceDetailStore()
const toast = useToastStore()
const api = useApi()

const isDraft = computed(() => detail.selectedVersion?.status === 'draft')

// ---- two-secret model (service-level) ----
const serviceSecretInput = ref('')
const webhookKeyInput = ref('')
const savingSecrets = ref(false)

async function saveSecrets() {
  if (!detail.service) return
  const payload: Record<string, string> = {}
  if (serviceSecretInput.value) payload.service_secret = serviceSecretInput.value
  if (webhookKeyInput.value) payload.webhook_signing_key = webhookKeyInput.value
  if (Object.keys(payload).length === 0) {
    toast.show('Paste a value to set or rotate a secret.')
    return
  }
  savingSecrets.value = true
  try {
    await api.patch(`/admin/services/${detail.service.id}`, payload)
    await detail.reloadService()
    serviceSecretInput.value = ''
    webhookKeyInput.value = ''
    toast.show('Secret(s) updated.')
  } catch {
    toast.show('Could not update secrets.')
  } finally {
    savingSecrets.value = false
  }
}

// ---- version-scoped execution settings ----
const coinCost = ref(0)
const regenerateLimit = ref(0)
const responseTimeoutS = ref(60)
const getIntervalS = ref(10)
const maxGetAttempts = ref(10)
const postUrl = ref('')
const getUrl = ref('')
const savingVersion = ref(false)

watchEffect(() => {
  const v = detail.selectedVersion
  if (!v) return
  coinCost.value = v.coin_cost
  regenerateLimit.value = v.regenerate_limit
  responseTimeoutS.value = v.response_timeout_s
  getIntervalS.value = v.get_interval_s
  maxGetAttempts.value = v.max_get_attempts
  postUrl.value = v.post_url ?? ''
  getUrl.value = v.get_url ?? ''
})

async function saveVersionSettings() {
  if (!detail.selectedVersion) return
  savingVersion.value = true
  try {
    await api.patch(`/admin/versions/${detail.selectedVersion.id}`, {
      coin_cost: coinCost.value,
      regenerate_limit: regenerateLimit.value,
      response_timeout_s: responseTimeoutS.value,
      get_interval_s: getIntervalS.value,
      max_get_attempts: maxGetAttempts.value,
      post_url: postUrl.value || null,
      get_url: getUrl.value || null,
    })
    await detail.reloadVersion(detail.selectedVersion.id)
    toast.show('Execution settings saved.')
  } catch {
    toast.show('Could not save execution settings.')
  } finally {
    savingVersion.value = false
  }
}

async function duplicateToDraft() {
  if (!detail.selectedVersion) return
  try {
    const res = await api.post<{ data: { id: string } }>(`/admin/versions/${detail.selectedVersion.id}/duplicate`, {})
    await detail.reloadVersions()
    detail.selectVersion(res.data.id)
    toast.show('Duplicated to a new draft — edit it there.')
  } catch {
    toast.show('Could not duplicate this version.')
  }
}
</script>

<template>
  <div class="flex-1 overflow-auto bg-[#F6F7F9] p-8">
    <div class="mx-auto flex max-w-[720px] flex-col gap-5">
      <!-- two-secret model -->
      <div class="rounded-[22px] border border-[#ECECEE] bg-white p-7 shadow-[0_1px_2px_rgba(133,151,171,.05)]">
        <div class="mb-1 text-base font-bold">Secrets</div>
        <div class="mb-5 text-[13px] text-[#7D7E80]">
          Both are paste-only — we never generate a value, and neither is ever shown again once set.
        </div>

        <label class="mb-2 block text-[13px] font-semibold">Service key <span class="font-normal text-[#969799]">· Bearer token external calls present to authenticate as this service</span></label>
        <div class="mb-1 flex items-center gap-2">
          <span v-if="detail.service?.has_secret" class="inline-flex items-center gap-1.5 rounded-full bg-[#E8F8EE] px-3 py-1 text-xs font-bold text-[#168A40]">Set · {{ detail.service.secret_preview }}</span>
          <span v-else class="inline-flex items-center gap-1.5 rounded-full bg-[#F4F5F7] px-3 py-1 text-xs font-bold text-[#8A8F98]">Not set</span>
        </div>
        <input v-model="serviceSecretInput" type="password" autocomplete="new-password" placeholder="Paste to set or rotate…" class="mb-5 h-[46px] w-full rounded-xl border border-[#DCE0E7] bg-[#FAFBFD] px-4 font-mono text-[13px]">

        <label class="mb-2 block text-[13px] font-semibold">Webhook signing key <span class="font-normal text-[#969799]">· HMAC-signs result webhooks from this service</span></label>
        <div class="mb-1 flex items-center gap-2">
          <span v-if="detail.service?.has_webhook_signing_key" class="inline-flex items-center gap-1.5 rounded-full bg-[#E8F8EE] px-3 py-1 text-xs font-bold text-[#168A40]">Set · {{ detail.service.webhook_signing_key_preview }}</span>
          <span v-else class="inline-flex items-center gap-1.5 rounded-full bg-[#F4F5F7] px-3 py-1 text-xs font-bold text-[#8A8F98]">Not set</span>
        </div>
        <input v-model="webhookKeyInput" type="password" autocomplete="new-password" placeholder="Paste to set or rotate…" class="mb-5 h-[46px] w-full rounded-xl border border-[#DCE0E7] bg-[#FAFBFD] px-4 font-mono text-[13px]">

        <button class="h-11 rounded-full bg-[#5639E5] px-6 text-[13.5px] font-semibold text-white disabled:opacity-60" :disabled="savingSecrets" @click="saveSecrets">
          {{ savingSecrets ? 'Saving…' : 'Save secrets' }}
        </button>
      </div>

      <!-- API connection (read-only reference) -->
      <div class="rounded-[22px] border border-[#E7E0FB] bg-gradient-to-br from-[#F7F5FF] to-[#FBFAFF] p-7">
        <div class="mb-1 text-base font-bold">API connection for v{{ detail.selectedVersion?.version_no }}</div>
        <div class="mb-4 text-[13px] text-[#7D7E80]">Your service receives a POST here, and we poll the GET endpoint back.</div>
        <div class="space-y-2 font-mono text-[12.5px] text-[#4B4C4D]">
          <div class="rounded-lg bg-white/70 px-3 py-2">POST → {{ postUrl || '(not set)' }}</div>
          <div class="rounded-lg bg-white/70 px-3 py-2">GET ← {{ getUrl || '(not set)' }}</div>
        </div>
      </div>

      <!-- version-scoped execution settings -->
      <div class="rounded-[22px] border border-[#ECECEE] bg-white p-7 shadow-[0_1px_2px_rgba(133,151,171,.05)]">
        <div class="mb-1 flex items-center justify-between">
          <div class="text-base font-bold">Execution & pricing — v{{ detail.selectedVersion?.version_no }}</div>
          <span class="rounded-full px-2.5 py-1 text-[11px] font-bold" :class="isDraft ? 'bg-[#F0EDFE] text-[#5639E5]' : 'bg-[#F4F5F7] text-[#8A8F98]'">{{ detail.selectedVersion?.status }}</span>
        </div>
        <div v-if="!isDraft" class="mb-5 flex items-center gap-2 rounded-xl bg-[#FFF7E5] px-4 py-3 text-[12.5px] font-semibold text-[#966B0A]">
          This version is {{ detail.selectedVersion?.status }} and frozen. Duplicate it to a new draft to make changes.
          <button class="ml-auto rounded-full bg-[#966B0A] px-3 py-1.5 text-white" @click="duplicateToDraft">Duplicate to draft</button>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <label class="flex flex-col gap-1.5">
            <span class="text-[13px] font-semibold">Credits per request</span>
            <input v-model.number="coinCost" :disabled="!isDraft" type="number" min="0" class="h-11 rounded-lg border border-[#DCE0E7] bg-[#FAFBFD] px-3 text-sm disabled:bg-[#F4F5F7] disabled:text-[#8A8F98]">
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="text-[13px] font-semibold">Regenerate limit</span>
            <input v-model.number="regenerateLimit" :disabled="!isDraft" type="number" min="0" class="h-11 rounded-lg border border-[#DCE0E7] bg-[#FAFBFD] px-3 text-sm disabled:bg-[#F4F5F7] disabled:text-[#8A8F98]">
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="text-[13px] font-semibold">Response timeout (s)</span>
            <input v-model.number="responseTimeoutS" :disabled="!isDraft" type="number" min="1" class="h-11 rounded-lg border border-[#DCE0E7] bg-[#FAFBFD] px-3 text-sm disabled:bg-[#F4F5F7] disabled:text-[#8A8F98]">
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="text-[13px] font-semibold">Poll interval (s)</span>
            <input v-model.number="getIntervalS" :disabled="!isDraft" type="number" min="1" class="h-11 rounded-lg border border-[#DCE0E7] bg-[#FAFBFD] px-3 text-sm disabled:bg-[#F4F5F7] disabled:text-[#8A8F98]">
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="text-[13px] font-semibold">Max poll attempts</span>
            <input v-model.number="maxGetAttempts" :disabled="!isDraft" type="number" min="1" class="h-11 rounded-lg border border-[#DCE0E7] bg-[#FAFBFD] px-3 text-sm disabled:bg-[#F4F5F7] disabled:text-[#8A8F98]">
          </label>
        </div>

        <label class="mb-2 mt-4 block text-[13px] font-semibold">POST url <span class="font-normal text-[#969799]">· where we submit new orders</span></label>
        <input v-model="postUrl" :disabled="!isDraft" class="mb-4 h-11 w-full rounded-lg border border-[#DCE0E7] bg-[#FAFBFD] px-3 font-mono text-[13px] disabled:bg-[#F4F5F7] disabled:text-[#8A8F98]">
        <label class="mb-2 block text-[13px] font-semibold">GET url <span class="font-normal text-[#969799]">· where we poll for a result</span></label>
        <input v-model="getUrl" :disabled="!isDraft" class="mb-5 h-11 w-full rounded-lg border border-[#DCE0E7] bg-[#FAFBFD] px-3 font-mono text-[13px] disabled:bg-[#F4F5F7] disabled:text-[#8A8F98]">

        <button v-if="isDraft" class="h-11 rounded-full bg-[#5639E5] px-6 text-[13.5px] font-semibold text-white disabled:opacity-60" :disabled="savingVersion" @click="saveVersionSettings">
          {{ savingVersion ? 'Saving…' : 'Save' }}
        </button>
      </div>
    </div>
  </div>
</template>
