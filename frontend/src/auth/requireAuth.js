import { redirect } from "react-router-dom";
import { fetchSessionUser } from "./session";

function forbidden(message = "Forbidden") {
  throw new Response(message, { status: 403, statusText: "Forbidden" });
}

/**
 * Loader that requires a valid session. Redirects to /login on 401.
 * Reuses the deduped session request, so nested loaders share one GET /user.
 */
export async function requireAuth() {
  const user = await fetchSessionUser();
  if (!user) {
    throw redirect("/login");
  }
  return user;
}

/**
 * Require admin or analyst role (admin hub, orders, notifications).
 */
export async function requireAnalyst() {
  const user = await requireAuth();
  const slug = user?.role?.slug;
  if (slug !== "admin" && slug !== "analyst") {
    forbidden();
  }
  return user;
}

/**
 * Require admin role only (user management).
 */
export async function requireAdmin() {
  const user = await requireAuth();
  if (user?.role?.slug !== "admin") {
    forbidden();
  }
  return user;
}
