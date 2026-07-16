interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE'
  params?: Record<string, unknown>
  body?: unknown
}

/**
 * Every call is authenticated the same way orders are on the Laravel side:
 * a core-issued bearer token, never Sanctum/cookies (see auth.core
 * middleware). $fetch/ofetch auto-detects FormData bodies (file uploads),
 * so no Content-Type wrangling is needed here.
 */
export function useApi() {
  const config = useRuntimeConfig()
  const auth = useAuthStore()

  function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
    return $fetch<T>(path, {
      baseURL: config.public.apiBase,
      method: options.method ?? 'GET',
      params: options.params,
      body: options.body as any,
      headers: {
        Accept: 'application/json',
        ...auth.authHeader,
      },
    })
  }

  return {
    get: <T>(path: string, params?: Record<string, unknown>) => request<T>(path, { method: 'GET', params }),
    post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body }),
    patch: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body }),
    del: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
  }
}
