import { useState } from "react";
import {
  Link,
  useLoaderData,
  useParams,
  useSearchParams,
} from "react-router-dom";
import ProductCard from "../components/ProductCard";
import Pagination from "../components/Pagination";
import "../styles/catalog.css";

export default function StoreProducts() {
  const { storeId } = useParams();
  const { store, products, pagination, error } = useLoaderData();
  const [searchParams, setSearchParams] = useSearchParams();

  const [statusFilter, setStatusFilter] = useState(
    searchParams.get("status") || "all",
  );

  const applyFilters = (e) => {
    e.preventDefault();
    const next = new URLSearchParams();
    if (statusFilter && statusFilter !== "all")
      next.set("status", statusFilter);
    next.set("page", "1");
    setSearchParams(next);
  };

  const handlePageChange = (newPage) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      next.set("page", String(newPage));
      return next;
    });
    window.scrollTo({ top: 0, behavior: "smooth" });
  };

  const storeName = store?.name || `Магазин #${storeId}`;

  return (
    <div className="catalog-page">
      <div className="catalog-card products-container">
        <div className="catalog-header">
          <h1>{storeName}</h1>
          <p>Каталог товаров продавца</p>
          <p>
            <Link to="/stores" className="category-link">
              ← Все магазины
            </Link>
          </p>
        </div>

        <form className="filters-form" onSubmit={applyFilters}>
          <div className="filters-wrapper">
            <select
              className="profile-input filter-select"
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
            >
              <option value="all">Все товары</option>
              <option value="in_stock">В наличии</option>
            </select>
            <button className="save-button filter-button" type="submit">
              Фильтровать
            </button>
          </div>
        </form>

        {error ? (
          <div className="status-message error">{error}</div>
        ) : (
          <>
            <div className="products-grid">
              {products.length === 0 ? (
                <div
                  className="status-message error"
                  style={{ gridColumn: "1 / -1" }}
                >
                  Товары не найдены.
                </div>
              ) : null}

              {products.map((product) => (
                <ProductCard key={product.id} product={product} />
              ))}
            </div>

            <Pagination
              currentPage={pagination.current_page}
              lastPage={pagination.last_page}
              onPageChange={handlePageChange}
            />
          </>
        )}
      </div>
    </div>
  );
}
