// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  modules: ['@nuxtjs/tailwindcss', '@pinia/nuxt'],

  // Nuxt prefixes components in subfolders by default (components/use/Foo.vue
  // -> <UseFoo>); disabled so <WizardForm>/<ChatForm>/etc. resolve by their
  // own filename regardless of which components/ subfolder they live in.
  components: [{ path: '~/components', pathPrefix: false }],

  tailwindcss: {
    cssPath: '~/assets/css/main.css',
    configPath: 'tailwind.config.js',
  },

  app: {
    head: {
      title: 'AIHD Lab · Marketplace',
    },
  },

  runtimeConfig: {
    public: {
      // The Laravel API this client talks to (Phases 1-5, already on main).
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost/api',
      // Local/dev only: the seeded core-stub bearer token (see config/core.php
      // stub.dev_token). A real deployment supplies a token from the core
      // team's own auth flow instead of this fallback.
      devToken: process.env.NUXT_PUBLIC_DEV_TOKEN || 'dev-token',
      broadcastKey: process.env.NUXT_PUBLIC_PUSHER_APP_KEY || '',
      broadcastHost: process.env.NUXT_PUBLIC_PUSHER_HOST || '',
      broadcastPort: process.env.NUXT_PUBLIC_PUSHER_PORT || '443',
      broadcastScheme: process.env.NUXT_PUBLIC_PUSHER_SCHEME || 'https',
      broadcastCluster: process.env.NUXT_PUBLIC_PUSHER_APP_CLUSTER || 'mt1',
    },
  },
})
