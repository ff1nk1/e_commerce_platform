import { api } from "./client";

export function getNotifications(params) {
  return api.get("/notifications", { params });
}

export function getNotification(id) {
  return api.get(`/notifications/${id}`);
}

export function resendNotification(id) {
  return api.post(`/notifications/${id}/resend`);
}
