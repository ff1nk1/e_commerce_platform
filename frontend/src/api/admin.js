import { api } from "./client";

export function getAdminUsers(params) {
  return api.get("/admin/users", { params });
}

export function getAdminRoles() {
  return api.get("/admin/roles");
}

export function updateUserRole(userId, roleId) {
  return api.patch(`/admin/users/${userId}/role`, { role_id: Number(roleId) });
}

export function getAdminOrders(params) {
  return api.get("/admin/orders", { params });
}

export function shipOrder(orderId) {
  return api.post(`/admin/orders/${orderId}/ship`);
}
