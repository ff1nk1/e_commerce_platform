import { redirect } from "react-router-dom";
import { requireAuth } from "../auth/requireAuth";
import { getOrder } from "../api/orders";

export async function ordersLoader() {
  await requireAuth();
  return null;
}

export async function orderDetailLoader({ params }) {
  await requireAuth();
  try {
    const { data } = await getOrder(params.id);
    return { order: data };
  } catch {
    throw redirect("/orders");
  }
}
