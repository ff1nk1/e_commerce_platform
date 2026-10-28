import { useRouteError, isRouteErrorResponse, Link } from "react-router-dom";
import "../styles/errors.css";

export default function RootErrorBoundary() {
  const error = useRouteError();

  // Достаем статус из ошибки React Router (404) ИЛИ из Axios (403, 500)
  const status = isRouteErrorResponse(error)
    ? error.status
    : error?.response?.status;

  if (status === 403) {
    return (
      <div className="error-page">
        <div className="error-card">
          <div className="error-badge">403</div>
          <h1 className="error-title">Доступ запрещен</h1>
          <p className="error-description">
            У вас недостаточно прав (ABAC) для просмотра этого раздела.
          </p>
          <div className="error-actions">
            <Link to="/profile" className="btn btn-primary">
              Вернуться в профиль
            </Link>
          </div>
        </div>
      </div>
    );
  }

  if (status === 404) {
    return (
      <div className="error-page">
        <div className="error-card">
          <div className="error-badge error-badge-neutral">404</div>
          <h1 className="error-title">Страница не найдена</h1>
          <p className="error-description">
            Запрашиваемый адрес не существует или был перемещен.
          </p>
          <div className="error-actions">
            <Link to="/profile" className="btn btn-primary">
              Вернуться в профиль
            </Link>
          </div>
        </div>
      </div>
    );
  }

  // Для 500 и любых других непредвиденных ошибок
  return (
    <div className="error-page">
      <div className="error-card">
        <div className="error-badge">500</div>
        <h1 className="error-title">Что-то пошло не так</h1>
        <p className="error-description">
          {error?.message ||
            "Произошла непредвиденная ошибка на стороне сервера."}
        </p>
        <div className="error-actions">
          <button
            onClick={() => window.location.reload()}
            className="btn btn-primary"
          >
            Перезагрузить страницу
          </button>
        </div>
      </div>
    </div>
  );
}
