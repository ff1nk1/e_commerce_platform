import { useMemo } from "react";
import { useLoaderData, useSearchParams } from "react-router-dom";
import SearchInput from "./search/SearchInput";
import SearchFilters from "./search/SearchFilters";
import SearchResults from "./search/SearchResults";
import {
  EMPTY_FACETS,
  hasActiveFilters as computeHasActiveFilters,
  readSelection,
} from "./search/searchParams";
import "../styles/catalog.css";

export default function Search() {
  const { products, facets, pagination, error } = useLoaderData();
  const [searchParams, setSearchParams] = useSearchParams();
  const loading = false;

  const selection = useMemo(() => readSelection(searchParams), [searchParams]);
  const hasActiveFilters = useMemo(
    () => computeHasActiveFilters(searchParams),
    [searchParams],
  );

  const qParam = searchParams.get("q") || "";
  const sortValue =
    selection.sort || (searchParams.get("q") ? "relevance" : "new");

  const updateParams = (mutator) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      mutator(next);
      next.delete("page");
      return next;
    });
  };

  const onSubmitQuery = (query) => {
    if (!query) {
      setSearchParams({}, { replace: true });
      return;
    }
    updateParams((next) => next.set("q", query));
  };

  const onClearQuery = () => setSearchParams({}, { replace: true });

  const onClearFilters = () => {
    const q = searchParams.get("q");
    setSearchParams(q ? { q } : {}, { replace: true });
  };

  const onToggleCategory = (id) => {
    const idStr = String(id);
    updateParams((next) => {
      const current = next.getAll("category_id");
      next.delete("category_id");
      const exists = current.includes(idStr);
      const nextIds = exists
        ? current.filter((v) => v !== idStr)
        : [...current, idStr];
      nextIds.forEach((v) => next.append("category_id", v));
    });
  };

  const onToggleInStock = (value) => {
    updateParams((next) => {
      const nextValue = value ? "1" : "0";
      if (next.get("in_stock") === nextValue) next.delete("in_stock");
      else next.set("in_stock", nextValue);
    });
  };

  const onApplyPriceRange = (range) => {
    updateParams((next) => {
      if (selection.priceRange === range.key) {
        next.delete("price_range");
        next.delete("min_price");
        next.delete("max_price");
        return;
      }
      next.set("price_range", range.key);
      if (range.from !== null && range.from !== undefined) {
        next.set("min_price", String(range.from));
      } else {
        next.delete("min_price");
      }
      if (range.to !== null && range.to !== undefined) {
        const exclusiveMaxPrice = range.to - 0.01;
        next.set("max_price", String(exclusiveMaxPrice));
      } else {
        next.delete("max_price");
      }
    });
  };

  const onToggleAttr = (name, value) => {
    updateParams((next) => {
      const key = `attr[${name}]`;
      const current = next.getAll(key);
      next.delete(key);
      const exists = current.includes(value);
      const nextValues = exists
        ? current.filter((v) => v !== value)
        : [...current, value];
      nextValues.forEach((v) => next.append(key, v));
    });
  };

  const onSortChange = (sort) => {
    updateParams((next) => {
      if (sort) next.set("sort", sort);
      else next.delete("sort");
    });
  };

  const onPageChange = (newPage) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      next.set("page", String(newPage));
      return next;
    });
    window.scrollTo({ top: 0, behavior: "smooth" });
  };

  return (
    <div className="catalog-page">
      <div className="catalog-card search-layout">
        <div className="catalog-header">
          <h1>Поиск</h1>
          <SearchInput
            initialQuery={qParam}
            sortValue={sortValue}
            onSortChange={onSortChange}
            onSubmitQuery={onSubmitQuery}
            onClear={onClearQuery}
          />
        </div>

        <div className="search-body">
          <SearchFilters
            facets={facets ?? EMPTY_FACETS}
            selection={selection}
            hasActiveFilters={hasActiveFilters}
            onToggleCategory={onToggleCategory}
            onToggleInStock={onToggleInStock}
            onApplyPriceRange={onApplyPriceRange}
            onToggleAttr={onToggleAttr}
            onClearAll={onClearFilters}
          />
          <SearchResults
            products={products}
            pagination={pagination}
            loading={loading}
            error={error}
            onPageChange={onPageChange}
          />
        </div>
      </div>
    </div>
  );
}
