import { api } from "./client";

export function login(email, password) {
  return api.post("/login", { email, password });
}

export function register(formData) {
  return api.post("/registration", formData);
}

export function logout() {
  return api.post("/logout", {});
}

export function getUser() {
  return api.get("/user");
}

export function updateProfile(field, value) {
  return api.patch("/profile", { [field]: value });
}

export function updatePassword(payload) {
  return api.patch("/profile/password", payload);
}
