interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE'
  params?: Record<string, unknown>
  body?: unknown
}

/**
 * Every call is authenticated the same way orders are on the Laravel side: a
 * core-issued bearer token (see auth.core middleware) -- except under Phase
 * L2's LAB_AUTH_MODE=anonymous, where auth.core resolves to AnonymousAuth
 * instead and identity is a signed cookie, not a token. `credentials:
 * 'include'` is what lets that cookie survive a real cross-origin request
 * (this app and the API are typically served from different origins/ports);
 * it changes nothing for the bearer-token path, which never relied on
 * cookies either way. $fetch/ofetch auto-detects FormData bodies (file
 * uploads), so no Content-Type wrangling is needed here.
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
      credentials: 'include',
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
