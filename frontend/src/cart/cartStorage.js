const STORAGE_KEY = "shop_cart_v1";

/**
 * @typedef {{ product_id: number, name: string, price: number|string, image_url?: string|null, store_id?: number|null, quantity: number }} CartItem
 */

/** @returns {CartItem[]} */
export function loadCart() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed
      .filter(
        (item) =>
          item &&
          typeof item.product_id === "number" &&
          typeof item.quantity === "number" &&
          item.quantity >= 1,
      )
      .map((item) => ({
        product_id: item.product_id,
        name: String(item.name ?? ""),
        price: item.price,
        image_url: item.image_url ?? null,
        store_id: item.store_id ?? null,
        quantity: Math.max(1, Math.floor(item.quantity)),
      }));
  } catch {
    return [];
  }
}

/** @param {CartItem[]} items */
export function saveCart(items) {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
}
