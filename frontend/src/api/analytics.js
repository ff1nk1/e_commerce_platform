import { api } from "./client";

export function getUsersAnalytics(params) {
  return api.get("/analytics/users", { params });
}

export function getOrdersAnalytics(params) {
  return api.get("/analytics/orders", { params });
}

export function getSearchAnalytics(params) {
  return api.get("/analytics/search", { params });
}

export function getStoresAnalytics(params) {
  return api.get("/analytics/orders/stores", { params });
}

export function getCategoriesAnalytics(params) {
  return api.get("/analytics/orders/categories", { params });
}
