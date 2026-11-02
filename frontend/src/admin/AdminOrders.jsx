import { useLoaderData, useSearchParams, useFetcher } from "react-router-dom";
import { formatMoney } from "../utils/money";
import { formatDateTime } from "../utils/date";
import "../styles/admin.css";

export default function AdminOrders() {
  const { orders, meta } = useLoaderData();
  const [, setSearchParams] = useSearchParams();
  const fetcher = useFetcher();

  const page = meta?.current_page || 1;
  const lastPage = meta?.last_page || 1;
  const shippingId = fetcher.formData?.get("order_id");
  const actionError = fetcher.data?.error;

  const goToPage = (next) => {
    if (next < 1 || next > lastPage) return;
    setSearchParams((prev) => {
      const params = new URLSearchParams(prev);
      params.set("page", String(next));
      return params;
    });
  };

  return (
    <div>
      <div className="admin-header">
        <h2>Заказы</h2>
      </div>

      {actionError && <div className="analytics-error">{actionError}</div>}

      <div className="table-wrapper">
        {orders.length === 0 ? (
          <div className="table-empty">Заказы не найдены</div>
        ) : (
          <table className="admin-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>User ID</th>
                <th>Статус</th>
                <th>Сумма</th>
                <th>Создан</th>
                <th>Действия</th>
              </tr>
            </thead>
            <tbody>
              {orders.map((order) => (
                <tr key={order.id}>
                  <td className="id-cell">{order.id}</td>
                  <td>{order.user_id ?? "—"}</td>
                  <td>
                    <span className={`status-badge status-${order.status}`}>
                      {order.status || "—"}
                    </span>
                  </td>
                  <td>{formatMoney(order.total_amount)}</td>
                  <td>{formatDateTime(order.created_at)}</td>
                  <td>
                    {order.status === "confirmed" ? (
                      <fetcher.Form method="post">
                        <input type="hidden" name="order_id" value={order.id} />
                        <button
                          type="submit"
                          className="btn btn-primary"
                          disabled={shippingId === String(order.id)}
                        >
                          {shippingId === String(order.id) ? "…" : "Отгрузить"}
                        </button>
                      </fetcher.Form>
                    ) : (
                      "—"
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {orders.length > 0 && (
        <div className="pagination">
          <div className="pagination-info">
            Показано {meta?.from}–{meta?.to} из {meta?.total}
          </div>
          <div className="pagination-controls">
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => goToPage(page - 1)}
              disabled={page <= 1}
            >
              Назад
            </button>
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => goToPage(page + 1)}
              disabled={page >= lastPage}
            >
              Вперед
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
