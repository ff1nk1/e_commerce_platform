import { useState } from "react";
import { useLoaderData, useSearchParams } from "react-router-dom";
import CatalogSearchBar from "../components/CatalogSearchBar";
import ProductCard from "../components/ProductCard";
import Pagination from "../components/Pagination";
import "../styles/catalog.css";

export default function CategoryProducts() {
  const { category, products, pagination, error } = useLoaderData();
  const [searchParams, setSearchParams] = useSearchParams();

  const [minPrice, setMinPrice] = useState(searchParams.get("min_price") || "");
  const [maxPrice, setMaxPrice] = useState(searchParams.get("max_price") || "");
  const [sortBy, setSortBy] = useState(searchParams.get("sort") || "new");

  const applyFilters = (e) => {
    e.preventDefault();
    const next = new URLSearchParams();
    if (minPrice) next.set("min_price", minPrice);
    if (maxPrice) next.set("max_price", maxPrice);
    next.set("sort", sortBy);
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

  return (
    <div className="catalog-page">
      <div className="catalog-card products-container">
        <div className="catalog-header">
          <h1>{category ? category.name : "Загрузка..."}</h1>
          <CatalogSearchBar />
        </div>

        <form className="filters-form" onSubmit={applyFilters}>
          <div className="filters-wrapper">
            <input
              className="profile-input filter-input"
              type="number"
              placeholder="Мин. цена"
              value={minPrice}
              onChange={(e) => setMinPrice(e.target.value)}
            />
            <input
              className="profile-input filter-input"
              type="number"
              placeholder="Макс. цена"
              value={maxPrice}
              onChange={(e) => setMaxPrice(e.target.value)}
            />
            <select
              className="profile-input filter-select"
              value={sortBy}
              onChange={(e) => setSortBy(e.target.value)}
            >
              <option value="new">Сначала новые</option>
              <option value="price_asc">Сначала дешевые</option>
              <option value="price_desc">Сначала дорогие</option>
            </select>
            <button className="save-button filter-button" type="submit">
              Применить
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
                  По вашему запросу товары не найдены.
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
