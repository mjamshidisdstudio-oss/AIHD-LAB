<script setup lang="ts">
// Not shown in the design (its "New service" button has no described modal
// markup) — collects only the fields StoreServiceRequest actually requires
// up front (slug/name/kind/category [+external_url if external]); everything
// else is filled in from the editor's Overview tab after creation. Flagged
// in the PR.
import { useCatalogStore } from '~/stores/catalog'
import { useToastStore } from '~/stores/toast'

const emit = defineEmits<{ close: [] }>()
const catalog = useCatalogStore()
const toast = useToastStore()
const router = useRouter()

const name = ref('')
const slug = ref('')
const kind = ref<'internal' | 'external'>('internal')
const category = ref('interior')
const externalUrl = ref('')
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

watch(name, (value) => {
  slug.value = slugify(value)
})
function slugify(value: string) {
  return value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '')
}

async function submit() {
  saving.value = true
  errors.value = {}
  try {
    const service = await catalog.create({
      slug: slug.value,
      name: name.value,
      kind: kind.value,
      category: category.value,
      ...(kind.value === 'external' ? { external_url: externalUrl.value } : {}),
    })
    toast.show('Service created.')
    emit('close')
    router.push(`/services/${service.id}`)
  } catch (e: any) {
    errors.value = e?.data?.errors ?? {}
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/30 px-4" @click.self="emit('close')">
    <div class="w-full max-w-[420px] rounded-[20px] bg-white p-7 shadow-2xl">
      <h2 class="mb-5 text-xl font-extrabold tracking-[-.02em]">New service</h2>
      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <label class="flex flex-col gap-1.5">
          <span class="text-[13px] font-semibold text-[#4B4C4D]">Name</span>
          <input v-model="name" required class="h-[46px] rounded-xl border border-[#EBEBED] px-4 text-[14.5px] focus:border-[#5639E5]" placeholder="Seasonal Views">
        </label>
        <label class="flex flex-col gap-1.5">
          <span class="text-[13px] font-semibold text-[#4B4C4D]">Slug</span>
          <input v-model="slug" required class="h-[46px] rounded-xl border border-[#EBEBED] px-4 font-mono text-[13.5px] focus:border-[#5639E5]" placeholder="seasonal-views">
          <span v-if="errors.slug" class="text-xs font-medium text-[#D70D3E]">{{ errors.slug[0] }}</span>
        </label>
        <label class="flex flex-col gap-1.5">
          <span class="text-[13px] font-semibold text-[#4B4C4D]">Category</span>
          <input v-model="category" required class="h-[46px] rounded-xl border border-[#EBEBED] px-4 text-[14.5px] focus:border-[#5639E5]" placeholder="interior">
        </label>
        <div class="flex flex-col gap-1.5">
          <span class="text-[13px] font-semibold text-[#4B4C4D]">Service type</span>
          <div class="flex gap-2">
            <button type="button" class="flex-1 rounded-xl border py-2.5 text-[13.5px] font-semibold" :class="kind === 'internal' ? 'border-[#5639E5] bg-[#F0EDFE] text-[#5639E5]' : 'border-[#EBEBED] text-[#4B4C4D]'" @click="kind = 'internal'">Built-in</button>
            <button type="button" class="flex-1 rounded-xl border py-2.5 text-[13.5px] font-semibold" :class="kind === 'external' ? 'border-[#5639E5] bg-[#F0EDFE] text-[#5639E5]' : 'border-[#EBEBED] text-[#4B4C4D]'" @click="kind = 'external'">External</button>
          </div>
        </div>
        <label v-if="kind === 'external'" class="flex flex-col gap-1.5">
          <span class="text-[13px] font-semibold text-[#4B4C4D]">External URL</span>
          <input v-model="externalUrl" required type="url" class="h-[46px] rounded-xl border border-[#EBEBED] px-4 text-[14.5px] focus:border-[#5639E5]" placeholder="https://…">
        </label>

        <div class="mt-2 flex gap-2">
          <button type="button" class="h-11 flex-1 rounded-full border border-[#E1E4E8] bg-white text-[13.5px] font-semibold text-[#4B4C4D]" @click="emit('close')">Cancel</button>
          <button type="submit" :disabled="saving" class="h-11 flex-1 rounded-full bg-[#5639E5] text-[13.5px] font-semibold text-white disabled:opacity-60">{{ saving ? 'Creating…' : 'Create' }}</button>
        </div>
      </form>
    </div>
  </div>
</template>
