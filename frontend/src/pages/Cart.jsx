import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useCart } from "../cart/CartContext";
import { createOrder } from "../api/orders";
import { formatImageUrl, PLACEHOLDER_IMAGE } from "../utils/formatImageUrl";
import "../styles/orders.css";
import "../styles/cart.css";

export default function Cart() {
  const { items, updateQuantity, removeItem, clear, totalCount, totalPrice } =
    useCart();
  const navigate = useNavigate();
  const [checkingOut, setCheckingOut] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  const handleCheckout = async () => {
    if (items.length === 0 || checkingOut) return;
    setCheckingOut(true);
    setError("");
    setMessage("");
    try {
      const { data } = await createOrder(
        items.map((item) => ({
          product_id: item.product_id,
          quantity: item.quantity,
        })),
        crypto.randomUUID(),
      );
      clear();
      setMessage("Заказ создан.");
      navigate(`/orders/${data.id}`);
    } catch (err) {
      const msg =
        err.response?.data?.message ||
        err.response?.data?.errors?.items?.[0] ||
        "Не удалось создать заказ. Нужен локальный снапшот товара в order_service.";
      setError(msg);
    } finally {
      setCheckingOut(false);
    }
  };

  return (
    <div className="orders-page">
      <div className="orders-container">
        <div className="orders-card">
          <div className="orders-header">
            <h1>Корзина</h1>
            <p>
              {totalCount > 0
                ? `${totalCount} поз. · к оплате ${totalPrice.toFixed(2)} ₽`
                : "Добавьте товары со страницы товара"}
            </p>
          </div>

          {error && <div className="status-message error">{error}</div>}
          {message && <div className="status-message success">{message}</div>}

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
                        {Number(item.price).toFixed(2)} ₽ × {item.quantity} ={" "}
                        {(Number(item.price) * item.quantity).toFixed(2)} ₽
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
                  Итого: <strong>{totalPrice.toFixed(2)} ₽</strong>
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
