import { useCallback, useState } from "react";
import { useLoaderData } from "react-router-dom";
import { getAdminOrders, shipOrder } from "../api/admin";

function formatDate(dateString) {
  if (!dateString) return "—";
  return new Date(dateString).toLocaleString("ru-RU", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });
}

export default function AdminOrders() {
  const initial = useLoaderData();
  const [orders, setOrders] = useState(initial?.data || []);
  const [pagination, setPagination] = useState({
    currentPage: initial?.current_page || 1,
    lastPage: initial?.last_page || 1,
    total: initial?.total || 0,
    from: initial?.from || 0,
    to: initial?.to || 0,
  });
  const [page, setPage] = useState(initial?.current_page || 1);
  const [isLoading, setIsLoading] = useState(false);
  const [shippingId, setShippingId] = useState(null);
  const [actionError, setActionError] = useState("");

  const fetchOrders = useCallback(async (targetPage = 1) => {
    setIsLoading(true);
    try {
      const response = await getAdminOrders({
        page: targetPage,
        per_page: 15,
      });
      const data = response.data;
      setOrders(data.data || []);
      setPagination({
        currentPage: data.current_page || 1,
        lastPage: data.last_page || 1,
        total: data.total || 0,
        from: data.from || 0,
        to: data.to || 0,
      });
      setPage(data.current_page || targetPage);
    } catch (error) {
      console.error("Orders fetch error:", error);
    } finally {
      setIsLoading(false);
    }
  }, []);

  const handlePageChange = (newPage) => {
    if (newPage >= 1 && newPage <= pagination.lastPage) {
      fetchOrders(newPage);
    }
  };

  const handleShip = async (orderId) => {
    setActionError("");
    setShippingId(orderId);
    try {
      await shipOrder(orderId);
      await fetchOrders(page);
    } catch (error) {
      console.error("Ship order error:", error);
      setActionError(
        error.response?.data?.message ||
          error.response?.data?.errors?.status?.[0] ||
          "Не удалось отгрузить заказ",
      );
    } finally {
      setShippingId(null);
    }
  };

  return (
    <div>
      <div className="admin-header">
        <h2>Заказы</h2>
      </div>

      {actionError && <div className="analytics-error">{actionError}</div>}

      <div className="table-wrapper">
        {isLoading ? (
          <div className="table-loading">Загрузка данных...</div>
        ) : orders.length === 0 ? (
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
                  <td>{order.total_amount ?? "—"}</td>
                  <td>{formatDate(order.created_at)}</td>
                  <td>
                    {order.status === "confirmed" ? (
                      <button
                        type="button"
                        className="btn btn-primary"
                        disabled={shippingId === order.id}
                        onClick={() => handleShip(order.id)}
                      >
                        {shippingId === order.id ? "…" : "Отгрузить"}
                      </button>
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

      {!isLoading && orders.length > 0 && (
        <div className="pagination">
          <div className="pagination-info">
            Показано {pagination.from}–{pagination.to} из {pagination.total}
          </div>
          <div className="pagination-controls">
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => handlePageChange(page - 1)}
              disabled={page <= 1}
            >
              Назад
            </button>
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => handlePageChange(page + 1)}
              disabled={page >= pagination.lastPage}
            >
              Вперед
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
