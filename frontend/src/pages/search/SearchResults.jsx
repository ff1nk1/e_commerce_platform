import ProductCard from "../../components/ProductCard";
import Pagination from "../../components/Pagination";

/**
 * Search results grid + pagination. Reads are pure; page changes bubble up.
 *
 * @param {{
 *   products: object[],
 *   pagination: { current_page: number, last_page: number, total: number },
 *   loading?: boolean,
 *   error?: string|null,
 *   onPageChange: (page: number) => void,
 * }} props
 */
export default function SearchResults({
  products,
  pagination,
  loading = false,
  error = null,
  onPageChange,
}) {
  return (
    <div className="search-results">
      <div className="search-results-meta">Найдено: {pagination.total}</div>

      {error ? <div className="status-message error">{error}</div> : null}

      {loading ? (
        <div className="status-message">Поиск...</div>
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
            onPageChange={onPageChange}
          />
        </>
      )}
    </div>
  );
}
