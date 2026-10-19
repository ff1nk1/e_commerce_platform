const apiBase = import.meta.env.VITE_API_URL || '/api'

/**
 * Refresh the session cookie without going through the axios interceptor chain
 * (avoids circular dependency with api/client).
 */
export async function refreshSession() {
  try {
    const response = await fetch(`${apiBase}/refresh`, {
      method: 'POST',
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
    return response.ok
  } catch (error) {
    console.error('[AUTH] Не удалось обновить сессию', error)
    return false
  }
}
