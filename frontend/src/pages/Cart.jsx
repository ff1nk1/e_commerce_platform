import { useEffect } from "react";
import { Link, useFetcher, useNavigate } from "react-router-dom";
import { useCart } from "../cart/CartContext";
import { createOrder } from "../api/orders";
import { formatImageUrl, PLACEHOLDER_IMAGE } from "../utils/formatImageUrl";
import { formatCents, formatMoney, lineCents } from "../utils/money";
import "../styles/orders.css";
import "../styles/cart.css";

/** Route action: create an order from the cart items. */
export async function cartAction({ request }) {
  const formData = await request.formData();
  const items = JSON.parse(formData.get("items") || "[]");
  if (items.length === 0) {
    return { error: "Корзина пуста." };
  }
  try {
    const { data } = await createOrder(items, crypto.randomUUID());
    return { orderId: data.id };
  } catch (err) {
    return {
      error:
        err.response?.data?.message ||
        err.response?.data?.errors?.items?.[0] ||
        "Не удалось создать заказ.",
    };
  }
}

export default function Cart() {
  const { items, updateQuantity, removeItem, clear, totalCount, totalCents } =
    useCart();
  const fetcher = useFetcher();
  const navigate = useNavigate();

  const checkingOut = fetcher.state !== "idle";

  useEffect(() => {
    if (fetcher.state === "idle" && fetcher.data?.orderId) {
      clear();
      navigate(`/orders/${fetcher.data.orderId}`);
    }
  }, [fetcher.state, fetcher.data, clear, navigate]);

  const handleCheckout = () => {
    fetcher.submit(
      {
        intent: "checkout",
        items: JSON.stringify(
          items.map((item) => ({
            product_id: item.product_id,
            quantity: item.quantity,
          })),
        ),
      },
      { method: "post" },
    );
  };

  return (
    <div className="orders-page">
      <div className="orders-container">
        <div className="orders-card">
          <div className="orders-header">
            <h1>Корзина</h1>
            <p>
              {totalCount > 0
                ? `${totalCount} поз. · к оплате ${formatCents(totalCents)}`
                : "Добавьте товары со страницы товара"}
            </p>
          </div>

          {fetcher.data?.error ? (
            <div className="status-message error">{fetcher.data.error}</div>
          ) : null}

          {items.length === 0 ? (
            <div className="orders-empty">
              Корзина пуста. <Link to="/categories">Перейти в каталог →</Link>
            </div>
          ) : (
            <>
              <ul className="cart-list">
                {items.map((item) => (
                  <li key={item.product_id} className="cart-item">
                    <Link
                      to={`/products/${item.product_id}`}
                      className="cart-item-thumb"
                    >
                      <img
                        src={formatImageUrl(item.image_url)}
                        alt={item.name}
                        onError={(e) => {
                          e.target.onerror = null;
                          e.target.src = PLACEHOLDER_IMAGE;
                        }}
                      />
                    </Link>
                    <div className="cart-item-body">
                      <Link to={`/products/${item.product_id}`}>
                        <strong>{item.name}</strong>
                      </Link>
                      <div className="cart-item-meta">
                        {formatMoney(item.price)} × {item.quantity} ={" "}
                        {formatCents(lineCents(item))}
                      </div>
                      <div className="cart-item-actions">
                        <label>
                          Кол-во
                          <input
                            type="number"
                            min={1}
                            value={item.quantity}
                            onChange={(e) =>
                              updateQuantity(
                                item.product_id,
                                Math.max(1, Number(e.target.value) || 1),
                              )
                            }
                          />
                        </label>
                        <button
                          type="button"
                          className="order-btn order-btn-danger"
                          onClick={() => removeItem(item.product_id)}
                        >
                          Удалить
                        </button>
                      </div>
                    </div>
                  </li>
                ))}
              </ul>

              <div className="cart-footer">
                <div className="cart-total">
                  Итого: <strong>{formatCents(totalCents)}</strong>
                </div>
                <div className="order-actions">
                  <button
                    type="button"
                    className="order-btn"
                    onClick={() => clear()}
                    disabled={checkingOut}
                  >
                    Очистить
                  </button>
                  <button
                    type="button"
                    className="order-btn order-btn-primary"
                    onClick={handleCheckout}
                    disabled={checkingOut || items.length === 0}
                  >
                    {checkingOut ? "Оформление..." : "Оформить заказ"}
                  </button>
                </div>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
