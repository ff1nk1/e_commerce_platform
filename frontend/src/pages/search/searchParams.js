/**
 * Helpers that translate the search URL query string into API params and into
 * the facet selection state the UI needs. Keeping these pure makes Search.jsx
 * an orchestrator instead of a place where URL/state/fetch logic all mix.
 */

export const EMPTY_FACETS = {
  categories: [],
  price_ranges: [],
  availability: [],
  attributes: {},
};

/** Parse `attr[Name]=value` (repeatable) query keys into `{ Name: [value, ...] }`. */
export function parseAttrParams(searchParams) {
  const attrs = {};
  for (const [key, value] of searchParams.entries()) {
    const match = key.match(/^attr\[(.+)\]$/);
    if (!match) continue;
    const name = match[1];
    if (!attrs[name]) attrs[name] = [];
    if (value && !attrs[name].includes(value)) {
      attrs[name].push(value);
    }
  }
  return attrs;
}

/** Build the backend `/search` request params from the URL query string. */
export function buildRequestParams(searchParams) {
  const params = {};

  const q = searchParams.get("q");
  if (q) params.q = q;

  const categoryIds = searchParams.getAll("category_id");
  if (categoryIds.length === 1) {
    params.category_id = categoryIds[0];
  } else if (categoryIds.length > 1) {
    params.category_id = categoryIds;
  }

  ["min_price", "max_price", "in_stock", "sort", "page", "per_page"].forEach(
    (key) => {
      const value = searchParams.get(key);
      if (value !== null && value !== "") {
        params[key] = value;
      }
    },
  );

  const attrs = parseAttrParams(searchParams);
  if (Object.keys(attrs).length > 0) {
    params.attr = attrs;
  }

  return params;
}

/** Read the currently selected facets out of the URL. */
export function readSelection(searchParams) {
  return {
    categoryIds: searchParams.getAll("category_id").map(String),
    attrs: parseAttrParams(searchParams),
    inStock: searchParams.get("in_stock"),
    priceRange: searchParams.get("price_range"),
    sort: searchParams.get("sort"),
  };
}

/** True when anything other than the free-text query is active. */
export function hasActiveFilters(searchParams) {
  for (const key of searchParams.keys()) {
    if (key !== "q") return true;
  }
  return false;
}
