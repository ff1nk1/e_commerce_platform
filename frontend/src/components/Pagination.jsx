/**
 * Prev/next pagination control shared by catalog and search grids.
 *
 * @param {{
 *   currentPage?: number,
 *   lastPage?: number,
 *   onPageChange: (page: number) => void,
 *   disabled?: boolean,
 * }} props
 */
export default function Pagination({
  currentPage = 1,
  lastPage = 1,
  onPageChange,
  disabled = false,
}) {
  if (lastPage <= 1) return null;

  const current = currentPage || 1;

  return (
    <div className="pagination">
      <button
        type="button"
        className="pagination-button"
        disabled={disabled || current <= 1}
        onClick={() => onPageChange(current - 1)}
      >
        ← Назад
      </button>
      <span className="pagination-info">
        Страница {current} из {lastPage}
      </span>
      <button
        type="button"
        className="pagination-button"
        disabled={disabled || current >= lastPage}
        onClick={() => onPageChange(current + 1)}
      >
        Вперед →
      </button>
    </div>
  );
}
