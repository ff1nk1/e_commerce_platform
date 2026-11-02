import { requireAuth } from "../auth/requireAuth";
import { getOrders, getOrder, payOrder, cancelOrder } from "../api/orders";

export async function ordersLoader({ request }) {
  await requireAuth();
  const url = new URL(request.url);
  const page = Number(url.searchParams.get("page")) || 1;
  try {
    const { data, meta } = await getOrders({ page });
    return { orders: data, pagination: meta, error: null };
  } catch {
    return {
      orders: [],
      pagination: { current_page: 1, last_page: 1 },
      error: "Не удалось загрузить заказы.",
    };
  }
}

export async function orderDetailLoader({ params }) {
  await requireAuth();
  try {
    const { data } = await getOrder(params.id);
    return { order: data, error: null };
  } catch {
    return { order: null, error: "Заказ не найден." };
  }
}

/** Route action for order mutations (pay / cancel). */
export async function orderDetailAction({ params, request }) {
  await requireAuth();
  const formData = await request.formData();
  const intent = formData.get("intent");

  if (intent === "cancel") {
    try {
      const { data } = await cancelOrder(params.id);
      return { order: data, error: null };
    } catch (err) {
      return {
        order: null,
        error: err.response?.data?.message || "Не удалось отменить заказ.",
      };
    }
  }

  if (intent === "pay") {
    try {
      const { data } = await payOrder(params.id, crypto.randomUUID());
      return { checkoutUrl: data?.checkout_url ?? null, error: null };
    } catch (err) {
      return {
        checkoutUrl: null,
        error: err.response?.data?.message || "Не удалось начать оплату.",
      };
    }
  }

  return { error: null };
}
