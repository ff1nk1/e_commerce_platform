import { useState } from "react";
import { useNavigate } from "react-router-dom";
import useSearchAutocomplete from "../../hooks/useSearchAutocomplete";
import { formatImageUrl, PLACEHOLDER_IMAGE } from "../../utils/formatImageUrl";
import { formatMoney } from "../../utils/money";

/**
 * Search text input with debounced OpenSearch autocomplete.
 *
 * @param {{
 *   initialQuery?: string,
 *   sortValue: string,
 *   onSortChange: (sort: string) => void,
 *   onSubmitQuery: (query: string) => void,
 *   onClear: () => void,
 * }} props
 */
export default function SearchInput({
  initialQuery = "",
  sortValue,
  onSortChange,
  onSubmitQuery,
  onClear,
}) {
  const navigate = useNavigate();
  const [queryInput, setQueryInput] = useState(initialQuery);
  const [syncedQuery, setSyncedQuery] = useState(initialQuery);
  if (syncedQuery !== initialQuery) {
    setSyncedQuery(initialQuery);
    setQueryInput(initialQuery);
  }

  const {
    suggestions,
    open,
    setOpen,
    close: closeAutocomplete,
  } = useSearchAutocomplete(queryInput);

  const submit = (e) => {
    e.preventDefault();
    closeAutocomplete();
    onSubmitQuery(queryInput.trim());
  };

  const onChange = (e) => {
    const value = e.target.value;
    setQueryInput(value);
    setOpen(true);
    if (value === "") {
      onClear();
      closeAutocomplete();
    }
  };

  const selectSuggestion = (item) => {
    closeAutocomplete();
    navigate(`/products/${item.id}`);
  };

  return (
    <form className="filters-form search-main-form" onSubmit={submit}>
      <div className="filters-wrapper">
        <div
          className="search-autocomplete"
          onBlur={(e) => {
            if (!e.currentTarget.contains(e.relatedTarget)) {
              closeAutocomplete();
            }
          }}
        >
          <input
            className="profile-input filter-input"
            type="search"
            placeholder="Что ищете?"
            value={queryInput}
            onChange={onChange}
            onFocus={() => {
              if (suggestions.length > 0) setOpen(true);
            }}
            onKeyDown={(e) => {
              if (e.key === "Escape") closeAutocomplete();
            }}
            aria-autocomplete="list"
            autoComplete="off"
          />
          {open && suggestions.length > 0 ? (
            <ul className="search-autocomplete-list">
              {suggestions.map((item) => (
                <li key={item.id}>
                  <button
                    type="button"
                    className="search-autocomplete-item"
                    onMouseDown={(e) => e.preventDefault()}
                    onClick={() => selectSuggestion(item)}
                  >
                    <img
                      src={formatImageUrl(item.image_url)}
                      alt=""
                      className="search-autocomplete-thumb"
                      onError={(e) => {
                        e.target.onerror = null;
                        e.target.src = PLACEHOLDER_IMAGE;
                      }}
                    />
                    <span className="search-autocomplete-name">
                      {item.name}
                    </span>
                    {item.price != null ? (
                      <span className="search-autocomplete-price">
                        {formatMoney(item.price)}
                      </span>
                    ) : null}
                  </button>
                </li>
              ))}
            </ul>
          ) : null}
        </div>
        <select
          className="profile-input filter-select"
          value={sortValue}
          onChange={(e) => onSortChange(e.target.value)}
        >
          <option value="relevance">По релевантности</option>
          <option value="new">Сначала новые</option>
          <option value="price_asc">Сначала дешевые</option>
          <option value="price_desc">Сначала дорогие</option>
        </select>
        <button className="save-button filter-button" type="submit">
          Искать
        </button>
      </div>
    </form>
  );
}
