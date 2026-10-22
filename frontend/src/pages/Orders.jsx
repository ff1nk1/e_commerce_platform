import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { getOrders } from '../api/orders'
import '../styles/orders.css'

export default function Orders() {
  const [orders, setOrders] = useState([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const fetchOrders = useCallback(async (targetPage = 1) => {
    setLoading(true)
    setError('')
    try {
      const { data } = await getOrders({ page: targetPage })
      setOrders(data.data || [])
      setPage(data.current_page || 1)
      setLastPage(data.last_page || 1)
    } catch (err) {
      console.error(err)
      setError('Не удалось загрузить заказы.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    fetchOrders(1)
  }, [fetchOrders])

  return (
    <div className="orders-page">
      <div className="orders-container">
        <div className="orders-card">
          <div className="orders-header">
            <h1>Мои заказы</h1>
            <p>История и статус ваших заказов</p>
          </div>

          {error && <div className="status-message error">{error}</div>}

          {loading ? (
            <div className="status-message">Загрузка...</div>
          ) : orders.length === 0 ? (
            <div className="orders-empty">
              Заказов пока нет. Добавьте товары в <Link to="/cart">корзину</Link> или оформите заказ со страницы товара.
            </div>
          ) : (
            <ul className="orders-list">
              {orders.map((order) => (
                <li key={order.id} className="order-item">
                  <Link to={`/orders/${order.id}`}>
                    <div className="order-item-row">
                      <strong>Заказ #{order.id}</strong>
                      <span className={`order-status ${order.status}`}>{order.status}</span>
                    </div>
                    <div className="order-item-row">
                      <span>{order.total_amount} ₽</span>
                      <span>{order.created_at ? new Date(order.created_at).toLocaleString() : ''}</span>
                    </div>
                    {(order.items || []).length > 0 && (
                      <ul className="order-item-lines">
                        {(order.items || []).map((item) => (
                          <li key={item.id} className="order-item-line">
                            <span>
                              {item.product_snapshot?.name || `Товар #${item.product_id}`}
                            </span>
                            <span>
                              {item.quantity} × {item.unit_price} ₽
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

          {lastPage > 1 && (
            <div className="order-actions">
              <button
                className="order-btn"
                disabled={page <= 1 || loading}
                onClick={() => fetchOrders(page - 1)}
              >
                Назад
              </button>
              <span>
                {page} / {lastPage}
              </span>
              <button
                className="order-btn"
                disabled={page >= lastPage || loading}
                onClick={() => fetchOrders(page + 1)}
              >
                Вперёд
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
