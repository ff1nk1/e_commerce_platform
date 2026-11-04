import {
  getAdminOrders,
  getAdminRoles,
  getAdminUsers,
  shipOrder,
  updateUserRole,
} from "../api/admin";
import {
  getNotification,
  getNotifications,
  resendNotification,
} from "../api/notifications";
import { requireAdmin, requireAnalyst } from "../auth/requireAuth";
import { defaultAnalyticsRange } from "./analyticsDates";
import { ANALYTICS_DIMENSIONS } from "./analytics/configs";

const PER_PAGE = 15;

function pageParam(request) {
  const url = new URL(request.url);
  return Math.max(1, Number(url.searchParams.get("page")) || 1);
}

export function adminLayoutLoader() {
  return requireAnalyst();
}

export async function adminUsersLoader({ request }) {
  await requireAdmin();
  const [usersRes, rolesRes] = await Promise.all([
    getAdminUsers({ page: pageParam(request), per_page: PER_PAGE }),
    getAdminRoles(),
  ]);
  return {
    users: usersRes.data,
    meta: usersRes.meta,
    roles: rolesRes.data,
  };
}

/** Route action: change a user's role. */
export async function adminUsersAction({ request }) {
  await requireAdmin();
  const formData = await request.formData();
  try {
    await updateUserRole(formData.get("user_id"), formData.get("role_id"));
    return { status: { type: "success", message: "Роль обновлена" } };
  } catch (error) {
    return {
      status: {
        type: "error",
        message: error.response?.data?.message || "Не удалось сменить роль",
      },
    };
  }
}

export async function adminOrdersLoader({ request }) {
  await requireAnalyst();
  const response = await getAdminOrders({
    page: pageParam(request),
    per_page: PER_PAGE,
  });
  return { orders: response.data, meta: response.meta };
}

/** Route action: ship an order. */
export async function adminOrdersAction({ request }) {
  await requireAnalyst();
  const formData = await request.formData();
  try {
    await shipOrder(formData.get("order_id"));
    return { error: null };
  } catch (error) {
    return {
      error:
        error.response?.data?.message ||
        error.response?.data?.errors?.status?.[0] ||
        "Не удалось отгрузить заказ",
    };
  }
}

function notificationsParams(request) {
  const url = new URL(request.url);
  const filters = {
    status: url.searchParams.get("status") || "",
    email: url.searchParams.get("email") || "",
    date_from: url.searchParams.get("date_from") || "",
    date_to: url.searchParams.get("date_to") || "",
  };
  const params = { page: pageParam(request), per_page: PER_PAGE };
  Object.entries(filters).forEach(([key, value]) => {
    if (value) params[key] = value;
  });
  return { filters, params };
}

export async function adminNotificationsLoader({ request }) {
  await requireAnalyst();
  const { filters, params } = notificationsParams(request);
  try {
    const response = await getNotifications(params);
    return {
      logs: response.data,
      meta: response.meta,
      filters,
      error: null,
    };
  } catch {
    return {
      logs: [],
      meta: null,
      filters,
      error: "Не удалось загрузить уведомления",
    };
  }
}

export async function adminNotificationDetailLoader({ params }) {
  await requireAnalyst();
  return getNotification(params.id);
}

/**
 * Route action: resend a notification. Serves both the list (via fetcher,
 * reading `statusMessage`) and the detail page.
 */
export async function adminNotificationDetailAction({ params }) {
  await requireAnalyst();
  try {
    const response = await resendNotification(params.id);
    const data = response.data?.data ?? null;
    const retries = data?.retries ?? "—";
    const message = response.data?.message
      ? `${response.data.message} (попыток: ${retries})`
      : `Уведомление отправлено (попыток: ${retries})`;
    return {
      notification: data,
      message,
      statusMessage: { type: "success", message },
      error: null,
    };
  } catch (error) {
    const message =
      error.response?.data?.message || "Не удалось отправить повторно.";
    return {
      notification: null,
      message: null,
      statusMessage: { type: "error", message },
      error: message,
    };
  }
}

function analyticsRangeParams(request) {
  const url = new URL(request.url);
  const defaults = defaultAnalyticsRange();
  return {
    from: url.searchParams.get("from") || defaults.from,
    to: url.searchParams.get("to") || defaults.to,
  };
}

/**
 * Loader for every analytics dimension (`/admin/analytics/:dimension`).
 * Reads the range and the optional space id from the URL (source of truth),
 * then dispatches to the dimension's `load` function.
 */
export async function adminAnalyticsDimensionLoader({ params, request }) {
  await requireAnalyst();

  const config = ANALYTICS_DIMENSIONS[params.dimension];
  if (!config) {
    throw new Response("Not Found", { status: 404 });
  }

  const url = new URL(request.url);
  const { from, to } = analyticsRangeParams(request);
  const apiParams = { from, to };
  if (config.limit) apiParams.limit = config.limit;

  let spaceId = "";
  if (config.selector) {
    spaceId = url.searchParams.get(config.selector.param) || "";
    if (spaceId) apiParams[config.selector.param] = spaceId;
  }

  const data = await config.load(apiParams);

  return { dimension: params.dimension, config, data, from, to, spaceId };
}
