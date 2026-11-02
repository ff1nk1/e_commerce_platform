const apiBase = import.meta.env.VITE_API_URL || "/api";

/**
 * Refresh the session cookie without going through the axios interceptor chain
 * (avoids a circular dependency with api/client). The axios interceptor already
 * serializes concurrent refreshes, so this stays a thin fetch wrapper.
 *
 * @returns {Promise<boolean>} whether the cookie was refreshed
 */
export async function refreshSession() {
  try {
    const response = await fetch(`${apiBase}/refresh`, {
      method: "POST",
      credentials: "include",
      headers: { Accept: "application/json" },
    });
    return response.ok;
  } catch {
    return false;
  }
}

let inflight = null;

/**
 * Fetch the authenticated user exactly once per navigation.
 *
 * React Router runs the matched route loaders in parallel, so concurrent
 * callers share a single in-flight `GET /user`. The promise is cleared as soon
 * as it settles, which keeps the data fresh on the next navigation (no timers,
 * no stale cache).
 *
 * @returns {Promise<object|null>} the user, or null for guests
 */
export async function fetchSessionUser() {
  if (inflight) return inflight;

  inflight = (async () => {
    try {
      const response = await fetch(`${apiBase}/user`, {
        credentials: "include",
        headers: { Accept: "application/json" },
      });
      if (!response.ok) return null;
      return await response.json();
    } catch {
      return null;
    } finally {
      inflight = null;
    }
  })();

  return inflight;
}
