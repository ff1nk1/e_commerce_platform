import { Link, useLoaderData } from "react-router-dom";
import { formatMoney } from "../utils/money";
import { formatDateTime } from "../utils/date";
import "../styles/orders.css";

export default function Orders() {
  const { orders, error } = useLoaderData();

  return (
    <div className="orders-page">
      <div className="orders-container">
        <div className="orders-card">
          <div className="orders-header">
            <h1>Мои заказы</h1>
            <p>История и статус ваших заказов</p>
          </div>

          {error ? <div className="status-message error">{error}</div> : null}

          {orders.length === 0 ? (
            <div className="orders-empty">
              Заказов пока нет. Добавьте товары в{" "}
              <Link to="/cart">корзину</Link> или оформите заказ со страницы
              товара.
            </div>
          ) : (
            <ul className="orders-list">
              {orders.map((order) => (
                <li key={order.id} className="order-item">
                  <Link to={`/orders/${order.id}`}>
                    <div className="order-item-row">
                      <strong>Заказ #{order.id}</strong>
                      <span className={`order-status ${order.status}`}>
                        {order.status}
                      </span>
                    </div>
                    <div className="order-item-row">
                      <span>{formatMoney(order.total_amount)}</span>
                      <span>{formatDateTime(order.created_at, "")}</span>
                    </div>
                    {(order.items || []).length > 0 && (
                      <ul className="order-item-lines">
                        {(order.items || []).map((item) => (
                          <li key={item.id} className="order-item-line">
                            <span>
                              {item.product_snapshot?.name ||
                                `Товар #${item.product_id}`}
                            </span>
                            <span>
                              {item.quantity} × {formatMoney(item.unit_price)}
                            </span>
                          </li>
                        ))}
                      </ul>
                    )}
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </div>
  );
}
