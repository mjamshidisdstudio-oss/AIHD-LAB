// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  modules: ['@nuxtjs/tailwindcss', '@pinia/nuxt'],

  // Nuxt prefixes components in subfolders by default (components/builder/Foo.vue
  // -> <BuilderFoo>); disabled so components resolve by their own filename
  // regardless of which components/ subfolder they live in.
  components: [{ path: '~/components', pathPrefix: false }],

  tailwindcss: {
    cssPath: '~/assets/css/main.css',
    configPath: 'tailwind.config.js',
  },

  app: {
    head: {
      title: 'AIHD Lab · Admin',
    },
  },

  runtimeConfig: {
    public: {
      // The Laravel API this client talks to (Phases 1-6, already on main).
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost/api',
    },
  },
})
