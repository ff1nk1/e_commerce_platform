import { useState } from "react";
import { useNavigate } from "react-router-dom";
import useSearchAutocomplete from "../hooks/useSearchAutocomplete";
import { formatImageUrl, PLACEHOLDER_IMAGE } from "../utils/formatImageUrl";

export default function CatalogSearchBar({ className = "" }) {
  const navigate = useNavigate();
  const [query, setQuery] = useState("");
  const { suggestions, open, setOpen, close } = useSearchAutocomplete(query);

  const onSubmit = (e) => {
    e.preventDefault();
    close();
    const q = query.trim();
    navigate(q ? `/search?q=${encodeURIComponent(q)}` : "/search");
  };

  const onSelectSuggestion = (item) => {
    close();
    setQuery(item.name || "");
    navigate(`/products/${item.id}`);
  };

  return (
    <form
      className={`catalog-search-bar ${className}`.trim()}
      onSubmit={onSubmit}
    >
      <div
        className="search-autocomplete"
        onBlur={(e) => {
          if (!e.currentTarget.contains(e.relatedTarget)) {
            close();
          }
        }}
      >
        <input
          className="profile-input filter-input catalog-search-input"
          type="search"
          placeholder="Поиск товаров..."
          value={query}
          onChange={(e) => {
            setQuery(e.target.value);
            setOpen(true);
          }}
          onFocus={() => {
            if (suggestions.length > 0) setOpen(true);
          }}
          onKeyDown={(e) => {
            if (e.key === "Escape") close();
          }}
          aria-label="Поиск товаров"
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
                  onClick={() => onSelectSuggestion(item)}
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
                  <span className="search-autocomplete-name">{item.name}</span>
                  {item.price != null ? (
                    <span className="search-autocomplete-price">
                      {item.price} ₽
                    </span>
                  ) : null}
                </button>
              </li>
            ))}
          </ul>
        ) : null}
      </div>
      <button className="save-button filter-button" type="submit">
        Найти
      </button>
    </form>
  );
}
