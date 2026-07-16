interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE'
  params?: Record<string, unknown>
  body?: unknown
}

/**
 * Every call is authenticated with the admin's Sanctum bearer token. A 401
 * means the token is gone/expired — log out and bounce to /login rather than
 * leaving the UI stuck against a rejected request.
 */
export function useApi() {
  const config = useRuntimeConfig()
  const auth = useAuthStore()

  async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
    try {
      return await $fetch<T>(path, {
        baseURL: config.public.apiBase,
        method: options.method ?? 'GET',
        params: options.params,
        body: options.body as any,
        headers: {
          Accept: 'application/json',
          ...auth.authHeader,
        },
      })
    } catch (error: any) {
      if (error?.response?.status === 401) {
        await auth.logout()
        await navigateTo('/login')
      }
      throw error
    }
  }

  return {
    get: <T>(path: string, params?: Record<string, unknown>) => request<T>(path, { method: 'GET', params }),
    post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body }),
    patch: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body }),
    del: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
  }
}
