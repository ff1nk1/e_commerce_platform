import { Link, useLoaderData } from "react-router-dom";
import CatalogSearchBar from "../components/CatalogSearchBar";
import "../styles/catalog.css";

export default function Stores() {
  const { stores, error } = useLoaderData();

  return (
    <div className="catalog-page">
      <div className="catalog-card">
        <div className="catalog-header">
          <h1>Магазины</h1>
          <p>Выберите магазин, чтобы посмотреть его товары</p>
          <CatalogSearchBar />
        </div>

        {error ? (
          <div className="status-message error">{error}</div>
        ) : stores.length === 0 ? (
          <div className="status-message">Магазины не найдены.</div>
        ) : (
          <ul className="category-list">
            {stores.map((store) => (
              <li key={store.id} className="category-item">
                <Link
                  to={`/stores/${store.id}/products`}
                  className="category-link"
                >
                  {store.name}
                  <span className="arrow">→</span>
                </Link>
                {store.description ? (
                  <p
                    className="status-message"
                    style={{ margin: "0.25rem 0 0" }}
                  >
                    {store.description}
                  </p>
                ) : null}
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
