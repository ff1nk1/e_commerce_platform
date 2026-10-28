import { Link, useOutletContext } from "react-router-dom";

export default function AdminHub() {
  const { user } = useOutletContext();
  const isAdmin = user?.role?.slug === "admin";

  return (
    <div className="admin-hub">
      <p className="admin-hub-intro">Разделы панели управления</p>
      <ul className="admin-hub-list">
        {isAdmin && (
          <li>
            <Link to="/admin/users" className="admin-hub-link">
              <strong>Пользователи</strong>
              <span>Список аккаунтов и смена ролей</span>
            </Link>
          </li>
        )}
        <li>
          <Link to="/admin/orders" className="admin-hub-link">
            <strong>Заказы</strong>
            <span>Все заказы в системе</span>
          </Link>
        </li>
        <li>
          <Link to="/admin/notifications" className="admin-hub-link">
            <strong>Уведомления</strong>
            <span>Email-сообщения из MongoDB</span>
          </Link>
        </li>
        <li>
          <Link to="/admin/analytics/users" className="admin-hub-link">
            <strong>Аналитика: пользователи</strong>
            <span>Рост базы пользователей во времени</span>
          </Link>
        </li>
        <li>
          <Link to="/admin/analytics/orders" className="admin-hub-link">
            <strong>Аналитика: заказы</strong>
            <span>Воронка, конверсия и выручка</span>
          </Link>
        </li>
        <li>
          <Link to="/admin/analytics/search" className="admin-hub-link">
            <strong>Аналитика: поиск</strong>
            <span>Популярные и пустые запросы</span>
          </Link>
        </li>
        <li>
          <Link to="/admin/analytics/stores" className="admin-hub-link">
            <strong>Аналитика: магазины</strong>
            <span>Заказы и выручка по магазинам</span>
          </Link>
        </li>
        <li>
          <Link to="/admin/analytics/categories" className="admin-hub-link">
            <strong>Аналитика: категории</strong>
            <span>Заказы товаров по категориям</span>
          </Link>
        </li>
        <li>
          <a
            href="http://localhost:8123/play"
            target="_blank"
            rel="noreferrer"
            className="admin-hub-link"
          >
            <strong>ClickHouse Play</strong>
            <span>Встроенный SQL UI ClickHouse (localhost:8123/play)</span>
          </a>
        </li>
        {isAdmin && (
          <li>
            <a href="/admin-catalog" className="admin-hub-link">
              <strong>Каталог</strong>
              <span>Товары, категории и остатки (Filament)</span>
            </a>
          </li>
        )}
      </ul>
    </div>
  );
}
