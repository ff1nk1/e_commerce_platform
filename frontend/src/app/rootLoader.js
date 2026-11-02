import { fetchSessionUser } from "../auth/session";

/**
 * Root loader — resolves the session exactly once per navigation with a single
 * GET /user, shared by every descendant loader (they reuse the same request).
 * Guests resolve to `{ user: null }`; it never redirects.
 */
export async function rootLoader() {
  const user = await fetchSessionUser();
  return { user };
}
