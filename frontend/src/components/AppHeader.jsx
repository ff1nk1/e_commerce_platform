import { NavLink, Link } from "react-router-dom";
import CartNavLink from "../cart/CartNavLink";
import ThemeToggle from "../theme/ThemeToggle";
import "../styles/header.css";

function navClassName({ isActive }) {
  return isActive ? "app-header-link active" : "app-header-link";
}

function iconClassName({ isActive }) {
  return isActive ? "app-header-icon-link active" : "app-header-icon-link";
}

export default function AppHeader({ user }) {
  const showAdmin =
    user?.role?.slug === "admin" || user?.role?.slug === "analyst";

  return (
    <header className="app-header">
      <div className="app-header-inner">
        <Link to="/categories" className="app-header-brand">
          Магазин
        </Link>

        <nav className="app-header-nav" aria-label="Основная навигация">
          <NavLink to="/categories" className={navClassName}>
            Каталог
          </NavLink>
          <NavLink to="/search" className={navClassName}>
            Поиск
          </NavLink>
          <NavLink to="/assistant" className={navClassName}>
            ИИ помощник
          </NavLink>
          <NavLink to="/stores" className={navClassName}>
            Магазины
          </NavLink>
          <NavLink to="/orders" className={navClassName}>
            Заказы
          </NavLink>
          <CartNavLink className="app-header-link" />
          {showAdmin && (
            <NavLink to="/admin" className={navClassName}>
              Админка
            </NavLink>
          )}
        </nav>

        <div className="app-header-actions">
          <ThemeToggle />
          {user ? (
            <NavLink
              to="/profile"
              className={iconClassName}
              title="Профиль"
              aria-label="Профиль"
            >
              <svg
                width="20"
                height="20"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
              >
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                <circle cx="12" cy="7" r="4" />
              </svg>
            </NavLink>
          ) : (
            <Link to="/login" className="app-header-login">
              Войти
            </Link>
          )}
        </div>
      </div>
    </header>
  );
}
