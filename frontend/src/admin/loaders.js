import {
  getCategoriesAnalytics,
  getOrdersAnalytics,
  getSearchAnalytics,
  getStoresAnalytics,
  getUsersAnalytics,
} from '../api/analytics'
import { getAdminOrders, getAdminRoles, getAdminUsers } from '../api/admin'
import { getNotification } from '../api/notifications'
import { requireAdmin, requireAnalyst } from '../auth/requireAuth'
import { defaultAnalyticsRange } from './analyticsDates'

/** Unwrap Laravel `{ data: [...] }` or a bare array from an axios response body. */
function unwrapList(payload) {
  const nested = payload?.data
  if (Array.isArray(nested)) return nested
  if (Array.isArray(payload)) return payload
  return []
}

export async function adminLayoutLoader() {
  return requireAnalyst()
}

export async function adminUsersLoader() {
  await requireAdmin()
  const [usersRes, rolesRes] = await Promise.all([
    getAdminUsers({ page: 1, per_page: 15 }),
    getAdminRoles(),
  ])
  return {
    users: usersRes.data,
    roles: unwrapList(rolesRes.data),
  }
}

export async function adminOrdersLoader() {
  await requireAnalyst()
  const response = await getAdminOrders({ page: 1, per_page: 15 })
  return response.data
}

export async function adminNotificationsLoader() {
  await requireAnalyst()
  return null
}

export async function adminNotificationDetailLoader({ params }) {
  await requireAnalyst()
  const response = await getNotification(params.id)
  const nested = response.data?.data
  const unwrapped = nested ?? response.data
  return unwrapped
}

function analyticsRangeParams(request) {
  const url = new URL(request.url)
  let from = url.searchParams.get('from')
  let to = url.searchParams.get('to')
  if (!from || !to) {
    const defaults = defaultAnalyticsRange()
    from = from || defaults.from
    to = to || defaults.to
  }
  return { from, to }
}

export async function adminAnalyticsUsersLoader({ request }) {
  await requireAnalyst()
  const response = await getUsersAnalytics(analyticsRangeParams(request))
  return response.data
}

export async function adminAnalyticsOrdersLoader({ request }) {
  await requireAnalyst()
  const response = await getOrdersAnalytics(analyticsRangeParams(request))
  return response.data
}

export async function adminAnalyticsSearchLoader({ request }) {
  await requireAnalyst()
  const response = await getSearchAnalytics({
    ...analyticsRangeParams(request),
    limit: 20,
  })
  return response.data
}

export async function adminAnalyticsStoresLoader({ request }) {
  await requireAnalyst()
  const url = new URL(request.url)
  const params = { ...analyticsRangeParams(request), limit: 20 }
  const storeId = url.searchParams.get('store_id')
  if (storeId) params.store_id = storeId
  const response = await getStoresAnalytics(params)
  return response.data
}

export async function adminAnalyticsCategoriesLoader({ request }) {
  await requireAnalyst()
  const url = new URL(request.url)
  const params = { ...analyticsRangeParams(request), limit: 20 }
  const categoryId = url.searchParams.get('category_id')
  if (categoryId) params.category_id = categoryId
  const response = await getCategoriesAnalytics(params)
  return response.data
}
