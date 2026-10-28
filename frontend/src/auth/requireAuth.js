import { redirect } from "react-router-dom";
import { getUser } from "../api/auth";

/** In-flight + short-lived cache so nested loaders share one GET /user per navigation. */
let userRequest = null;
let cachedUser = null;
let cacheClearTimer = null;

function forbidden(message = "Forbidden") {
  throw new Response(message, { status: 403, statusText: "Forbidden" });
}

/**
 * Loader that requires a valid session. Redirects to /login on 401.
 */
export async function requireAuth() {
  if (cachedUser) {
    return cachedUser;
  }

  if (!userRequest) {
    userRequest = getUser()
      .then(({ data }) => {
        cachedUser = data;
        clearTimeout(cacheClearTimer);
        cacheClearTimer = setTimeout(() => {
          cachedUser = null;
        }, 100);
        return data;
      })
      .finally(() => {
        userRequest = null;
      });
  }

  try {
    return await userRequest;
  } catch {
    throw redirect("/login");
  }
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
