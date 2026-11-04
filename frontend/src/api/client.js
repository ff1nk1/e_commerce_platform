import { create } from "axios";
import { refreshSession } from "../auth/session";

const baseURL = import.meta.env.VITE_API_URL || "/api";

export const api = create({
  baseURL,
  headers: { Accept: "application/json" },
  withCredentials: true,
});

/** Keys that identify a Laravel paginator payload. */
const PAGINATOR_KEYS = [
  "current_page",
  "last_page",
  "per_page",
  "total",
  "from",
  "to",
];

/** A "pure" paginator: `{ data: [...], current_page, last_page, ... }`. */
function isPaginator(body) {
  return (
    !!body &&
    typeof body === "object" &&
    Array.isArray(body.data) &&
    PAGINATOR_KEYS.some((key) => key in body)
  );
}

/** Extract pagination metadata from a Laravel paginator payload. */
export function paginatorMeta(body) {
  if (!body || typeof body !== "object") return null;
  const count = Array.isArray(body.data) ? body.data.length : 0;
  return {
    current_page: body.current_page ?? 1,
    last_page: body.last_page ?? 1,
    per_page: body.per_page ?? count,
    total: body.total ?? count,
    from: body.from ?? null,
    to: body.to ?? null,
  };
}

/**
 * Normalize a paginator OR a bare array into `{ items, meta }`.
 * Used for paginators nested inside partials (e.g. `{ category, products }`),
 * which the response interceptor intentionally leaves untouched.
 */
export function toPage(body) {
  if (Array.isArray(body)) return { items: body, meta: null };
  if (isPaginator(body)) return { items: body.data, meta: paginatorMeta(body) };
  return { items: [], meta: null };
}

let isRefreshing = false;
let failedQueue = [];

function processQueue(error) {
  failedQueue.forEach(({ resolve, reject }) => {
    if (error) {
      reject(error);
    } else {
      resolve();
    }
  });
  failedQueue = [];
}

api.interceptors.response.use(
  (response) => {
    // Unwrap only pure Laravel paginator envelopes: `response.data` becomes the
    // list and pagination metadata is exposed on `response.meta`. Partial
    // payloads such as `{ category, products }` or `{ suggestions }` are kept
    // intact so `response.data` never requires guessing.
    if (isPaginator(response.data)) {
      response.meta = paginatorMeta(response.data);
      response.data = response.data.data;
    }
    return response;
  },
  async (error) => {
    const originalRequest = error.config;

    const shouldAttemptRefresh =
      error.response?.status === 401 &&
      !!originalRequest &&
      !originalRequest._retry &&
      !originalRequest.url?.includes("/refresh") &&
      !originalRequest.url?.includes("/login") &&
      !originalRequest.url?.includes("/registration");

    if (!shouldAttemptRefresh) {
      throw error;
    }

    if (isRefreshing) {
      return new Promise((resolve, reject) => {
        failedQueue.push({ resolve, reject });
      }).then(() => api(originalRequest));
    }

    isRefreshing = true;
    originalRequest._retry = true;

    let refreshed;
    try {
      refreshed = await refreshSession();
    } catch (err) {
      processQueue(err);
      throw err;
    } finally {
      isRefreshing = false;
    }

    if (!refreshed) {
      processQueue(new Error("Session expired"));
      throw error;
    }

    processQueue(null);
    return api(originalRequest);
  },
);
