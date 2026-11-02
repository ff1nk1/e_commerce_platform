/**
 * Search facet sidebar: categories, price ranges, availability and attributes.
 * Purely presentational — every interaction bubbles up to the URL mutators in
 * Search.jsx, so the URL stays the single source of truth.
 *
 * @param {{
 *   facets: object,
 *   selection: { categoryIds: string[], attrs: object, inStock: string|null, priceRange: string|null },
 *   hasActiveFilters: boolean,
 *   onToggleCategory: (id: string|number) => void,
 *   onToggleInStock: (value: boolean) => void,
 *   onApplyPriceRange: (range: object) => void,
 *   onToggleAttr: (name: string, value: string) => void,
 *   onClearAll: () => void,
 * }} props
 */
export default function SearchFilters({
  facets,
  selection,
  hasActiveFilters,
  onToggleCategory,
  onToggleInStock,
  onApplyPriceRange,
  onToggleAttr,
  onClearAll,
}) {
  const { categoryIds, attrs, inStock, priceRange } = selection;

  return (
    <aside className="search-facets">
      <div className="search-facets-header">
        <h2>Фильтры</h2>
        {hasActiveFilters ? (
          <button
            type="button"
            className="facet-clear-button"
            onClick={onClearAll}
          >
            Сбросить все
          </button>
        ) : null}
      </div>

      <section className="facet-group">
        <h3>Категории</h3>
        {(facets.categories || []).map((cat) => (
          <label key={cat.id} className="facet-option">
            <input
              type="checkbox"
              checked={categoryIds.includes(String(cat.id))}
              onChange={() => onToggleCategory(cat.id)}
            />
            <span>{cat.name}</span>
            <span className="facet-count">{cat.count}</span>
          </label>
        ))}
      </section>

      <section className="facet-group">
        <h3>Цена</h3>
        {(facets.price_ranges || []).map((range) => (
          <label key={range.key} className="facet-option">
            <input
              type="checkbox"
              checked={priceRange === range.key}
              onChange={() => onApplyPriceRange(range)}
            />
            <span>{range.label}</span>
            <span className="facet-count">{range.count}</span>
          </label>
        ))}
      </section>

      <section className="facet-group">
        <h3>Наличие</h3>
        {(facets.availability || []).map((item) => (
          <label key={String(item.value)} className="facet-option">
            <input
              type="checkbox"
              checked={inStock === (item.value ? "1" : "0")}
              onChange={() => onToggleInStock(item.value)}
            />
            <span>{item.value ? "В наличии" : "Нет в наличии"}</span>
            <span className="facet-count">{item.count}</span>
          </label>
        ))}
      </section>

      {Object.keys(facets.attributes || {}).length > 0 ? (
        <div className="facet-attributes">
          <h3 className="facet-attributes-title">Атрибуты</h3>
          {Object.entries(facets.attributes || {}).map(([name, values]) => {
            const selectedCount = (attrs[name] || []).length;
            return (
              <details
                key={name}
                className="facet-group facet-group--collapsible"
              >
                <summary className="facet-group-summary">
                  <span>{name}</span>
                  {selectedCount > 0 ? (
                    <span className="facet-selected-count">
                      {selectedCount}
                    </span>
                  ) : null}
                </summary>
                <div className="facet-group-body">
                  {values.map((item) => (
                    <label
                      key={`${name}-${item.value}`}
                      className="facet-option"
                    >
                      <input
                        type="checkbox"
                        checked={(attrs[name] || []).includes(item.value)}
                        onChange={() => onToggleAttr(name, item.value)}
                      />
                      <span>{item.value}</span>
                      <span className="facet-count">{item.count}</span>
                    </label>
                  ))}
                </div>
              </details>
            );
          })}
        </div>
      ) : null}
    </aside>
  );
}
